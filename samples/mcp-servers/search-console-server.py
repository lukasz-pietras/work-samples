# /// script
# requires-python = ">=3.11"
# dependencies = ["mcp>=1.2,<2","google-api-python-client>=2.150", "google-auth>=2.35"]
# ///
"""Read-only Google Search Console MCP server for Claude Code (lunae.pl).

Auth: an authorized_user ADC file (GOOGLE_APPLICATION_CREDENTIALS) with the
webmasters.readonly scope, written by ~/.config/search-console-mcp/login.sh.
Read-only on purpose: it cannot add/remove sites or sitemaps.
"""
import os
from datetime import date, timedelta

from google.oauth2.credentials import Credentials
from googleapiclient.discovery import build
from mcp.server.fastmcp import FastMCP

SCOPES = ["https://www.googleapis.com/auth/webmasters.readonly"]
mcp = FastMCP("search-console")
_svc = None


def svc():
    global _svc
    if _svc is None:
        creds = Credentials.from_authorized_user_file(os.environ["GOOGLE_APPLICATION_CREDENTIALS"], SCOPES)
        _svc = build("searchconsole", "v1", credentials=creds, cache_discovery=False)
    return _svc


@mcp.tool()
def list_sites() -> list[dict]:
    """List Search Console properties this account can see (siteUrl + permissionLevel).
    Domain properties look like 'sc-domain:lunae.pl', URL-prefix ones like 'https://lunae.pl/'."""
    return svc().sites().list().execute().get("siteEntry", [])


@mcp.tool()
def search_analytics(
    site_url: str,
    start_date: str = "",
    end_date: str = "",
    dimensions: list[str] | None = None,
    row_limit: int = 100,
    search_type: str = "web",
    filters: list[dict] | None = None,
    data_state: str = "all",
) -> dict:
    """Search performance (clicks, impressions, ctr, position).
    Dates YYYY-MM-DD (default: last 28 days). dimensions: any of query, page, country, device,
    date, searchAppearance. filters: e.g. [{"dimension":"page","operator":"contains","expression":"/produkt/"}]
    (operators: equals, notEquals, contains, notContains, includingRegex, excludingRegex).
    search_type: web, image, video, news, discover, googleNews. data_state 'all' includes fresh (partial) data."""
    end = end_date or date.today().isoformat()
    start = start_date or (date.fromisoformat(end) - timedelta(days=27)).isoformat()
    body = {
        "startDate": start, "endDate": end, "dimensions": dimensions or [],
        "rowLimit": max(1, min(row_limit, 25000)), "type": search_type, "dataState": data_state,
    }
    if filters:
        body["dimensionFilterGroups"] = [{"groupType": "and", "filters": filters}]
    res = svc().searchanalytics().query(siteUrl=site_url, body=body).execute()
    return {"startDate": start, "endDate": end, **res}


@mcp.tool()
def inspect_url(site_url: str, url: str, language: str = "pl-PL") -> dict:
    """URL Inspection: Google's indexed view of one URL (coverage/verdict, last crawl, canonical
    chosen by Google vs declared, robots/indexing state, rich results, mobile usability).
    url must belong to site_url. Quota ~2000/day per property."""
    body = {"inspectionUrl": url, "siteUrl": site_url, "languageCode": language}
    return svc().urlInspection().index().inspect(body=body).execute().get("inspectionResult", {})


@mcp.tool()
def list_sitemaps(site_url: str) -> list[dict]:
    """Sitemaps submitted for the property, with last download, warnings/errors and URL counts."""
    return svc().sitemaps().list(siteUrl=site_url).execute().get("sitemap", [])


@mcp.tool()
def get_sitemap(site_url: str, feedpath: str) -> dict:
    """Details for one submitted sitemap (feedpath = full sitemap URL)."""
    return svc().sitemaps().get(siteUrl=site_url, feedpath=feedpath).execute()


if __name__ == "__main__":
    mcp.run()
