#!/usr/bin/env python3
"""Read-only export of the lunae.pl Shoper admin REST API to raw JSON. Stdlib only.

Credentials come from the environment: SHOPER_URL, SHOPER_API_USER, SHOPER_API_PASSWORD
(on the VPS they live in ~/lunae/.env). Only GET requests are made, plus the POST /auth
token exchange. Output contains customer/order personal data (GDPR): keep it on the VPS,
never commit it. Each run writes <out>/<UTC stamp>/ atomically; full runs repoint <out>/latest.

  set -a; . ~/lunae/.env; set +a
  python3 shoper-export.py --out ~/lunae/shoper-export [--only products,product-stocks]
"""
import argparse, base64, json, os, shutil, sys, time
import urllib.error, urllib.parse, urllib.request
from datetime import datetime, timezone
from pathlib import Path

# List resources of the Shoper REST API. Ones the API user may not read (403) or this shop
# version lacks (404) are recorded as skipped in the manifest rather than failing the run.
RESOURCES = [
    # catalogue
    'products', 'product-stocks', 'product-images', 'product-files', 'categories', 'categories-tree',
    'producers', 'attributes', 'attribute-groups', 'options', 'option-values', 'option-groups',
    'availabilities', 'deliveries', 'units', 'taxes', 'gauges', 'collections', 'collection-products',
    'specialoffers', 'product-reviews',
    # bare 'metafields' answers 500; it must be scoped to an object type
    'metafields/system', 'metafields/product', 'metafields/category', 'metafields/order',
    'metafields/user', 'metafield-values',
    # customers (personal data)
    'users', 'user-addresses', 'user-groups', 'subscribers', 'subscriber-groups',
    # orders (personal data)
    'orders', 'order-products', 'statuses', 'parcels',
    # shop settings and content
    'languages', 'currencies', 'shippings', 'payments', 'zones', 'redirects', 'aboutpages', 'news',
]
PAGE_LIMIT = 50  # API maximum
MIN_INTERVAL = 0.55  # leaky bucket drains ~2 calls/s; stay just under it


class Api:
    def __init__(self, base, user, password):
        self.base = base.rstrip('/') + '/webapi/rest/'
        self.last = 0.0
        self.calls = 0
        basic = base64.b64encode(f'{user}:{password}'.encode()).decode()
        status, body, _ = self.request('auth', method='POST', headers={'Authorization': 'Basic ' + basic})
        if status != 200:
            sys.exit(f'auth failed: HTTP {status} {body.get("error_description", body)}')
        self.token = body['access_token']

    def request(self, path, method='GET', headers=None):
        # Cloudflare in front of lunae.pl bans the default Python-urllib user-agent (error 1010).
        headers = {'Accept': 'application/json', 'User-Agent': 'lunae-shoper-export/1.0', **(headers or {})}
        for attempt in range(6):
            time.sleep(max(0.0, MIN_INTERVAL - (time.monotonic() - self.last)))
            self.last = time.monotonic()
            self.calls += 1
            req = urllib.request.Request(self.base + path, method=method, headers=headers)
            try:
                with urllib.request.urlopen(req, timeout=60) as r:
                    return r.status, json.load(r), r.headers
            except urllib.error.HTTPError as e:
                if e.code in (429, 500, 502, 503, 504) and attempt < 5:
                    wait = float(e.headers.get('Retry-After') or 2 ** (attempt + 1))
                    print(f'  HTTP {e.code} on {path}, retrying in {wait:.0f}s', file=sys.stderr)
                    time.sleep(wait)
                    continue
                try:
                    body = json.load(e)
                except ValueError:
                    body = {}
                return e.code, body, e.headers
        raise RuntimeError(f'gave up on {path}')

    def get(self, path):
        return self.request(path, headers={'Authorization': 'Bearer ' + self.token})


def export_resource(api, name):
    """All records of a list resource, or (None, reason) when it is unreadable."""
    records, page, pages, count = [], 1, 1, None
    while page <= pages:
        status, body, _ = api.get(f'{name}?limit={PAGE_LIMIT}&page={page}')
        if status != 200:
            # Unknown resources answer 400 "Missing MODULE '<name>'" rather than 404.
            missing = status == 400 and 'Missing MODULE' in body.get('error_description', '')
            if page == 1 and (status in (403, 404) or missing):
                return None, f'HTTP {status} {body.get("error_description") or body.get("error", "")}'.strip()
            raise RuntimeError(f'{name} page {page}: HTTP {status} {body}')
        if isinstance(body, list):  # tree-style resources are not paginated
            return body, None
        count, pages = int(body['count']), int(body['pages'])
        records += body['list']
        page += 1
    if count is not None and len(records) != count:
        raise RuntimeError(f'{name}: collected {len(records)} of {count} records')
    return records, None


def main():
    ap = argparse.ArgumentParser(description=__doc__.split('\n')[0])
    ap.add_argument('--out', type=Path, required=True)
    ap.add_argument('--only', help='comma-separated resource names (default: all)')
    args = ap.parse_args()
    env = {k: os.environ.get(k) for k in ('SHOPER_URL', 'SHOPER_API_USER', 'SHOPER_API_PASSWORD')}
    if not all(env.values()):
        sys.exit('set SHOPER_URL, SHOPER_API_USER and SHOPER_API_PASSWORD (e.g. source ~/lunae/.env)')
    # Since the 2026-11-02 cutover lunae.pl is the WooCommerce shop, so the Shoper API is no longer there.
    host = urllib.parse.urlsplit(env['SHOPER_URL']).hostname or ''
    if host in ('lunae.pl', 'www.lunae.pl'):
        sys.exit(f'SHOPER_URL={env["SHOPER_URL"]} now serves WooCommerce, not Shoper. Set SHOPER_URL in ~/lunae/.env '
                 "to the shop's own Shoper address (Shoper panel → Ustawienia → Domeny, e.g. https://<shop>.shoparena.pl).")
    names = args.only.split(',') if args.only else RESOURCES

    os.umask(0o077)
    args.out.mkdir(parents=True, exist_ok=True)
    started = datetime.now(timezone.utc)
    stamp = started.strftime('%Y%m%dT%H%M%SZ')
    api = Api(env['SHOPER_URL'], env['SHOPER_API_USER'], env['SHOPER_API_PASSWORD'])
    tmp = args.out / f'.{stamp}.partial'
    tmp.mkdir()
    manifest = dict(source=env['SHOPER_URL'], started_at=started.isoformat(), resources={}, skipped={})
    try:
        for name in names:
            records, skipped = export_resource(api, name)
            if records is None:
                manifest['skipped'][name] = skipped
                print(f'{name:22} skipped ({skipped})')
                continue
            (tmp / f'{name.replace("/", "-")}.json').write_text(json.dumps(records, ensure_ascii=False, indent=1) + '\n')
            manifest['resources'][name] = len(records)
            print(f'{name:22} {len(records)}')
    except BaseException:
        shutil.rmtree(tmp, ignore_errors=True)
        raise
    manifest.update(finished_at=datetime.now(timezone.utc).isoformat(), api_calls=api.calls)
    (tmp / 'manifest.json').write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + '\n')

    final = args.out / stamp
    tmp.rename(final)
    if not args.only:  # latest always points at a complete export
        tmp_link = args.out / '.latest.new'
        tmp_link.unlink(missing_ok=True)
        tmp_link.symlink_to(stamp)
        tmp_link.replace(args.out / 'latest')
    print(f'→ {final} ({api.calls} API calls)')


if __name__ == '__main__':
    main()
