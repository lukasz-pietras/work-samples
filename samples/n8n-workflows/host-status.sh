#!/usr/bin/env bash
# Read-only host probes; Python owns collection, deadlines, and atomic output.
exec python3 - "$@" <<'PYTHON'
import concurrent.futures
import datetime as dt
import fnmatch
import json
import math
import os
from pathlib import Path
import re
import shutil
import signal
import subprocess
import sys
import tempfile
import time


EXPECTED_CONTAINERS = (
    "lunae-wordpress-1", "lunae-db-1", "lunae-listmonk-1",
    "lunae-listmonk-db-1", "n8n-n8n-1", "minecraft-fabric",
)
UNITS = (
    "lunae-backup.service", "n8n-backup.service", "lunae-wpcron.service",
    "lunae-backup-check.service", "caddy.service", "docker.service",
)
TIMERS = (
    "lunae-backup.timer", "n8n-backup.timer", "lunae-wpcron.timer",
    "lunae-backup-check.timer",
)
UTC = dt.timezone.utc
# Dependent Docker inspection shares this budget with all other subprocesses.
COMMAND_BUDGET_SECONDS = 12.0


def utc_iso(epoch):
    return dt.datetime.fromtimestamp(epoch, UTC).isoformat(timespec="seconds").replace("+00:00", "Z")


def parse_timestamp(value):
    """Docker RFC3339, systemd calendar strings/epoch usec -> UTC seconds."""
    if value is None:
        return None
    value = str(value).strip()
    if value.lower() in ("", "n/a", "0", "[not set]", "never"):
        return None
    if value.startswith("0001-01-01"):
        return None
    if value.isdecimal():
        return utc_iso(int(value) / 1_000_000)
    # datetime accepts microseconds; Docker may emit nanoseconds.
    normalized = re.sub(r"(\.\d{6})\d+", r"\1", value)
    try:
        parsed = dt.datetime.fromisoformat(normalized.replace("Z", "+00:00"))
        if parsed.tzinfo is not None:
            return utc_iso(parsed.timestamp())
    except ValueError:
        pass
    # Child commands always use UTC/C locale. Docker CreatedAt also has an offset.
    for fmt in ("%a %Y-%m-%d %H:%M:%S %Z", "%Y-%m-%d %H:%M:%S %Z",
                "%Y-%m-%d %H:%M:%S %z %Z", "%Y-%m-%d %H:%M:%S %z"):
        try:
            parsed = dt.datetime.strptime(value, fmt)
            if parsed.tzinfo is None:
                if not value.endswith((" UTC", " GMT")):
                    continue
                parsed = parsed.replace(tzinfo=UTC)
            return utc_iso(parsed.timestamp())
        except ValueError:
            continue
    raise ValueError("unrecognized timestamp")


def timestamp_field(value, label, errors):
    try:
        return parse_timestamp(value)
    except (ValueError, OverflowError, OSError):
        errors.append(label + ": invalid timestamp")
        return None


def command(args, deadline, timed=False):
    remaining = deadline - time.monotonic()
    if remaining <= 0:
        return "", "collection deadline reached"
    # GNU timeout is a hard outer guard; communicate also enforces the shared
    # budget and kills the process group, including TERM-resistant descendants.
    argv = (["timeout", "--kill-after=1s", "10"] if timed else []) + args
    env = os.environ.copy()
    env.update(LC_ALL="C", TZ="UTC", SYSTEMD_COLORS="0", SYSTEMD_PAGER="cat")
    process = None
    try:
        process = subprocess.Popen(
            argv, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
            text=True, encoding="utf-8", errors="replace", env=env,
            start_new_session=True,
        )
        try:
            output, _ = process.communicate(timeout=min(10.5 if timed else 2.0, remaining))
        except subprocess.TimeoutExpired:
            os.killpg(process.pid, signal.SIGKILL)
            process.communicate()
            return "", "timed out"
        if process.returncode:
            reason = "timed out" if process.returncode in (124, 137, -signal.SIGKILL) else "exit " + str(process.returncode)
            return output, reason
        return output, None
    except OSError:
        return "", "command unavailable"
    finally:
        if process is not None and process.poll() is None:
            try:
                os.killpg(process.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
            process.wait()


def disk_probe():
    result = {"mount": "/", "used_pct": None, "free_gb": None}
    errors = []
    try:
        usage = shutil.disk_usage("/")
        result.update(used_pct=math.ceil(100 * usage.used / usage.total),
                      free_gb=round(usage.free / (1024 ** 3), 1))
    except (OSError, ValueError, ZeroDivisionError):
        errors.append("disk: usage unavailable")
    return result, errors


def memory_probe():
    result = {"total_mb": None, "available_mb": None, "swap_used_mb": None}
    errors = []
    try:
        entries = {}
        for line in Path("/proc/meminfo").read_text().splitlines():
            key, value = line.split(":", 1)
            entries[key] = int(value.split()[0])
    except (OSError, ValueError, IndexError):
        return result, ["memory: meminfo unavailable"]
    for field, keys in (("total_mb", ("MemTotal",)), ("available_mb", ("MemAvailable",)),
                        ("swap_used_mb", ("SwapTotal", "SwapFree"))):
        try:
            kb = entries[keys[0]] - (entries[keys[1]] if len(keys) == 2 else 0)
            result[field] = round(kb / 1024)
        except KeyError:
            errors.append("memory: " + field + " unavailable")
    return result, errors


def load_probe(deadline):
    result = {"1m": None, "5m": None, "15m": None, "cpus": None}
    errors = []
    try:
        values = [float(value) for value in Path("/proc/loadavg").read_text().split()[:3]]
        if len(values) != 3 or not all(math.isfinite(value) for value in values):
            raise ValueError()
        result.update(zip(("1m", "5m", "15m"), values))
    except (OSError, ValueError):
        errors.append("load: loadavg unavailable")
    output, error = command(["nproc"], deadline)
    try:
        if error or int(output.strip()) < 1:
            raise ValueError()
        result["cpus"] = int(output.strip())
    except ValueError:
        errors.append("load: nproc " + (error or "invalid response"))
    return result, errors


def host_probe(deadline):
    output, error = command(["hostname", "-s"], deadline)
    if error or not output.strip():
        return None, ["host: hostname " + (error or "empty response")]
    return output.strip(), []


def containers_probe(deadline, now):
    result = {"containers": [], "expected_containers_missing": [], "stale_cli_containers": None}
    errors = []
    output, error = command(["docker", "ps", "-a", "--format", "{{json .}}"], deadline, timed=True)
    if error:
        return result, ["containers: docker ps " + error]
    try:
        rows = [json.loads(line) for line in output.splitlines() if line.strip()]
        if any(not isinstance(row, dict) or not isinstance(row.get("Names"), str) or not row["Names"] for row in rows):
            raise ValueError()
    except (ValueError, TypeError):
        return result, ["containers: invalid docker ps response"]
    names = {row["Names"] for row in rows}
    result["expected_containers_missing"] = [name for name in EXPECTED_CONTAINERS if name not in names]
    stale = 0
    stale_valid = True
    for row in rows:
        if row["Names"].startswith("lunae-cli-run-"):
            previous_errors = len(errors)
            created = timestamp_field(row.get("CreatedAt"), "stale_cli_containers", errors)
            if created is None:
                stale_valid = False
                if len(errors) == previous_errors:
                    errors.append("stale_cli_containers: creation time unavailable")
            elif now - dt.datetime.fromisoformat(created.replace("Z", "+00:00")).timestamp() > 24 * 3600:
                stale += 1
    result["stale_cli_containers"] = stale if stale_valid else None
    present = [name for name in EXPECTED_CONTAINERS if name in names]
    if not present:
        return result, errors
    # Select only public state fields, so inspect never collects Config.Env.
    template = ('{"name":{{json .Name}},"state":{{json .State.Status}},'
                '"health":{{if .State.Health}}{{json .State.Health.Status}}{{else}}"none"{{end}},'
                '"started_at":{{json .State.StartedAt}},"restart_count":{{json .RestartCount}}}')
    output, error = command(["docker", "inspect", "--type", "container", "--format", template] + present,
                            deadline, timed=True)
    if error:
        errors.append("containers: docker inspect " + error)
    inspected = {}
    for line in output.splitlines():
        try:
            row = json.loads(line)
            name = row["name"].lstrip("/")
            if name in present:
                inspected[name] = row
        except (ValueError, TypeError, KeyError, AttributeError):
            errors.append("containers: invalid docker inspect response")
    for name in present:
        entry = {"name": name, "state": None, "health": None, "started_at": None, "restart_count": None}
        row = inspected.get(name)
        if row is None:
            errors.append("containers: " + name + " inspection unavailable")
        else:
            for key in ("state", "health"):
                if isinstance(row.get(key), str) and row[key]:
                    entry[key] = row[key]
                else:
                    errors.append("containers: " + name + " " + key + " unavailable")
            entry["started_at"] = timestamp_field(row.get("started_at"), "containers: " + name + " started_at", errors)
            if "started_at" not in row:
                errors.append("containers: " + name + " started_at unavailable")
            count = row.get("restart_count")
            if type(count) is int and count >= 0:
                entry["restart_count"] = count
            else:
                errors.append("containers: " + name + " restart_count unavailable")
        result["containers"].append(entry)
    return result, errors


def systemd_probe(unit, timer, deadline):
    result = ({"unit": unit, "active": None, "last_trigger": None, "next_elapse": None} if timer else
              {"unit": unit, "active": None, "result": None, "exit_status": None, "last_exit": None})
    properties = (["ActiveState", "LastTriggerUSec", "NextElapseUSecRealtime"] if timer else
                  ["ActiveState", "Result", "ExecMainStatus", "ExecMainExitTimestamp"])
    args = ["systemctl", "show", unit, "--no-pager", "-p", "LoadState"]
    for prop in properties:
        args.extend(["-p", prop])
    output, error = command(args, deadline, timed=True)
    if error:
        return result, [unit + ": systemctl " + error]
    values = dict(line.split("=", 1) for line in output.splitlines() if "=" in line)
    if values.get("LoadState") in ("not-found", "error", "bad-setting"):
        return result, [unit + ": unit unavailable"]
    errors = []
    for prop in properties:
        if prop not in values:
            errors.append(unit + ": " + prop + " unavailable")
    result["active"] = values.get("ActiveState") or None
    if "ActiveState" in values and not result["active"]:
        errors.append(unit + ": empty ActiveState")
    if timer:
        result["last_trigger"] = timestamp_field(values.get("LastTriggerUSec"), unit + " last_trigger", errors)
        result["next_elapse"] = timestamp_field(values.get("NextElapseUSecRealtime"), unit + " next_elapse", errors)
    else:
        result["result"] = values.get("Result") or None
        if "Result" in values and not result["result"]:
            errors.append(unit + ": empty Result")
        status = values.get("ExecMainStatus")
        try:
            result["exit_status"] = int(status)
        except (TypeError, ValueError):
            if status is not None:
                errors.append(unit + ": invalid exit status")
        result["last_exit"] = timestamp_field(values.get("ExecMainExitTimestamp"), unit + " last_exit", errors)
    return result, errors


def failed_units_probe(deadline):
    output, error = command(["systemctl", "--failed", "--no-legend", "--plain", "--no-pager"], deadline, timed=True)
    if error:
        return [], ["failed_units: systemctl " + error]
    return [line.split()[0] for line in output.splitlines() if line.strip()], []


def backups_probe(now):
    result = dict.fromkeys(("lunae_last_success", "lunae_last_success_age_h", "lunae_newest_db_dump",
                            "lunae_newest_db_dump_age_h", "n8n_newest", "n8n_newest_age_h"))
    errors = []
    try:
        modified = Path("/var/lib/lunae-backup/last-success").stat().st_mtime
        result["lunae_last_success"] = utc_iso(modified)
        result["lunae_last_success_age_h"] = round((now - modified) / 3600, 1)
    except (OSError, ValueError, OverflowError):
        errors.append("backups: lunae last-success unavailable")
    for directory, pattern, key in (("/home/debian/lunae/backups", "db-auto-*.sql.gz", "lunae_newest_db_dump"),
                                     ("/home/debian/n8n/backups", "n8n-auto-*.tar.gz", "n8n_newest")):
        try:
            candidates = []
            with os.scandir(directory) as entries:
                for entry in entries:
                    if fnmatch.fnmatchcase(entry.name, pattern) and entry.is_file():
                        candidates.append((entry.stat().st_mtime, entry.name))
            if not candidates:
                errors.append("backups: " + key + " no matching files")
                continue
            modified, name = max(candidates)
            result[key] = name
            result[key + "_age_h"] = round((now - modified) / 3600, 1)
        except OSError:
            errors.append("backups: " + key + " unavailable")
    return result, errors


def reboot_probe():
    try:
        Path("/var/run/reboot-required").stat()
        return True, []
    except FileNotFoundError:
        return False, []
    except OSError:
        return None, ["reboot_required: marker unavailable"]


def empty_status(now):
    return {
        "schema": 1, "generated_at": utc_iso(now), "host": None,
        "disk": {"mount": "/", "used_pct": None, "free_gb": None},
        "memory": {"total_mb": None, "available_mb": None, "swap_used_mb": None},
        "load": {"1m": None, "5m": None, "15m": None, "cpus": None},
        "containers": [], "expected_containers_missing": [], "stale_cli_containers": None,
        "units": [], "timers": [], "failed_units": [],
        "backups": dict.fromkeys(("lunae_last_success", "lunae_last_success_age_h", "lunae_newest_db_dump",
                                   "lunae_newest_db_dump_age_h", "n8n_newest", "n8n_newest_age_h")),
        "reboot_required": None, "errors": [],
    }


def collect_status():
    now = time.time()
    deadline = time.monotonic() + COMMAND_BUDGET_SECONDS
    status = empty_status(now)
    jobs = [("disk", disk_probe, ()), ("memory", memory_probe, ()),
            ("load", load_probe, (deadline,)), ("host", host_probe, (deadline,)),
            ("containers", containers_probe, (deadline, now)),
            ("failed_units", failed_units_probe, (deadline,)),
            ("backups", backups_probe, (now,)), ("reboot_required", reboot_probe, ())]
    jobs.extend(("units", systemd_probe, (unit, False, deadline)) for unit in UNITS)
    jobs.extend(("timers", systemd_probe, (unit, True, deadline)) for unit in TIMERS)
    with concurrent.futures.ThreadPoolExecutor(max_workers=len(jobs)) as pool:
        futures = [(key, pool.submit(probe, *args)) for key, probe, args in jobs]
        for key, future in futures:
            try:
                value, errors = future.result()
                if key == "containers":
                    status.update(value)
                elif key in ("units", "timers"):
                    status[key].append(value)
                else:
                    status[key] = value
                status["errors"].extend(errors)
            except Exception:
                # Never include exception text: it could contain command output.
                status["errors"].append(key + ": probe failed")
    return status


def atomic_write(path, payload):
    path = Path(path)
    temporary = None
    try:
        fd, temporary = tempfile.mkstemp(prefix=".host-status-", suffix=".tmp", dir=path.parent)
        with os.fdopen(fd, "w", encoding="utf-8") as stream:
            stream.write(payload)
            stream.flush()
            os.fsync(stream.fileno())
            os.fchmod(stream.fileno(), 0o644)
        os.replace(temporary, path)
        temporary = None
    finally:
        if temporary is not None:
            os.unlink(temporary)


def main():
    try:
        status = collect_status()
    except Exception:
        status = empty_status(time.time())
        status["errors"].append("collection: unexpected failure")
    if any(arg != "--stdout" for arg in sys.argv[1:]):
        status["errors"].append("arguments: only --stdout is supported")
    payload = json.dumps(status, ensure_ascii=True, allow_nan=False, indent=2) + "\n"
    try:
        if "--stdout" in sys.argv[1:]:
            sys.stdout.write(payload)
            sys.stdout.flush()
        else:
            atomic_write(os.environ.get("HOST_STATUS_OUT", "/home/debian/n8n/status/host.json"), payload)
    except (OSError, ValueError):
        print("host-status: output could not be written", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
PYTHON
