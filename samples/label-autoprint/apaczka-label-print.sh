#!/usr/bin/env bash
# Auto-print Apaczka waybills on the Godex G330 (100x150 mm).
#
# Apaczka serves InPost labels as an A4 page with the A6 label in the top-left
# corner. For every fresh ~/Downloads/apaczka_*.pdf this crops each page to its
# printed content (vector, no rasterising), sends it to the Godex queue (which
# scales to fit 100x150), then moves original + cropped copy to the archive dir.
#
# Triggered by apaczka-label-print.path (systemd --user). Manual use:
#   apaczka-label-print            # process new downloads
#   apaczka-label-print FILE.pdf   # crop + print a specific file (kept in place)
#   DRY_RUN=1 apaczka-label-print FILE.pdf   # crop only, print nothing
set -uo pipefail

PRINTER="${APACZKA_PRINTER:-Godex_G330}"
DOWNLOADS="$(xdg-user-dir DOWNLOAD 2>/dev/null || echo "$HOME/Downloads")"
ARCHIVE="$DOWNLOADS/apaczka-wydrukowane"
MAX_AGE_MIN=10          # ignore older files (never reprint old downloads)
MARGIN=2                # pt of white kept around the label
MAX_W=340 MAX_H=460     # pt; larger content = not a single label -> print whole page

log() { echo "$*"; }
notify() { notify-send -a "Etykiety" -i printer "$1" "${2:-}" 2>/dev/null || true; }

# crop_pdf IN OUT -> 0 ok
crop_pdf() {
  local in="$1" out="$2" tmp n=0 parts=() line
  tmp="$(mktemp -d)"
  local -a boxes=()
  while read -r line; do boxes+=("$line"); done < <(
    gs -q -dNOPAUSE -dBATCH -dSAFER -sDEVICE=bbox "$in" 2>&1 | awk '/^%%HiResBoundingBox:/{print $2, $3, $4, $5}')
  [[ ${#boxes[@]} -gt 0 ]] || { rm -rf "$tmp"; return 1; }
  for box in "${boxes[@]}"; do
    n=$((n + 1))
    local ph x0 y0 x1 y1
    ph="$(pdfinfo -f "$n" -l "$n" "$in" | awk '/^Page +[0-9]+ size:/{print $6; exit}')"
    read -r x0 y0 x1 y1 <<<"$box"
    # pdftocairo crop origin is top-left; gs bbox origin is bottom-left
    read -r cx cy cw ch <<<"$(awk -v x0="$x0" -v y0="$y0" -v x1="$x1" -v y1="$y1" -v ph="$ph" -v m="$MARGIN" \
      'BEGIN{x=x0-m; if(x<0)x=0; y=ph-y1-m; if(y<0)y=0; printf "%d %d %d %d", x, y, x1-x0+2*m+1, y1-y0+2*m+1}')"
    if (( cw < 50 || ch < 50 )); then continue; fi            # blank page
    if (( cw > MAX_W || ch > MAX_H )); then                   # not a single label
      pdfseparate -f "$n" -l "$n" "$in" "$tmp/p$n.pdf" || { rm -rf "$tmp"; return 1; }
      log "page $n: content ${cw}x${ch}pt too big for one label, printing page scaled"
    else
      pdftocairo -pdf -f "$n" -l "$n" -x "$cx" -y "$cy" -W "$cw" -H "$ch" \
        -paperw "$cw" -paperh "$ch" -nocenter "$in" "$tmp/p$n.pdf" || { rm -rf "$tmp"; return 1; }
    fi
    parts+=("$tmp/p$n.pdf")
  done
  [[ ${#parts[@]} -gt 0 ]] || { rm -rf "$tmp"; return 1; }
  if [[ ${#parts[@]} -eq 1 ]]; then cp "${parts[0]}" "$out"; else pdfunite "${parts[@]}" "$out"; fi
  local rc=$?; rm -rf "$tmp"; return $rc
}

# handle IN [archive:yes|no]
handle() {
  local in="$1" archive="${2:-yes}" base out stamp
  pdfinfo "$in" >/dev/null 2>&1 || { log "skip (not a complete PDF yet): $in"; return 0; }
  base="$(basename "$in" .pdf)"
  stamp="$(date +%Y%m%d-%H%M%S)"
  if [[ "$archive" == yes ]]; then
    mkdir -p "$ARCHIVE"
    out="$ARCHIVE/${base}_${stamp}_100x150.pdf"
  else
    out="$(dirname "$in")/${base}_100x150.pdf"
  fi
  if ! crop_pdf "$in" "$out"; then
    log "crop failed: $in"; notify "Etykieta: błąd przycinania" "$(basename "$in")"; return 1
  fi
  if [[ -n "${DRY_RUN:-}" ]]; then
    log "dry run, cropped: $out"
  elif lp -d "$PRINTER" -o media=w283h425 -o print-scaling=fit -t "$base" "$out" >/dev/null; then
    log "printed $(pdfinfo "$out" | awk '/^Pages:/{print $2}') page(s): $out"
    notify "Drukuję etykietę" "$base → $PRINTER"
  else
    log "lp failed: $out"; notify "Etykieta: błąd drukowania" "$base (sprawdź drukarkę / IP)"; return 1
  fi
  [[ "$archive" == yes ]] && mv -f "$in" "$ARCHIVE/${base}_${stamp}_a4.pdf"
  return 0
}

if [[ $# -gt 0 ]]; then
  rc=0; for f in "$@"; do handle "$f" no || rc=1; done; exit $rc
fi

exec 9>"${XDG_RUNTIME_DIR:-/tmp}/apaczka-label-print.lock"
flock 9
# loop until nothing new (files may land while we print)
for _ in 1 2 3 4 5; do
  mapfile -t files < <(find "$DOWNLOADS" -maxdepth 1 -type f -iname 'apaczka_*.pdf' \
    ! -iname '*_100x150.pdf' -mmin -"$MAX_AGE_MIN" -size +1k | sort)
  [[ ${#files[@]} -gt 0 ]] || break
  progress=0
  for f in "${files[@]}"; do
    handle "$f" yes && [[ ! -e "$f" ]] && progress=1
  done
  [[ $progress -eq 1 ]] || break
  sleep 1
done
