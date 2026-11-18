#!/usr/bin/env python3
"""PreToolUse hook for the Discord Claude: Meta Ads MCP is read-only.

Allowlist (not denylist): any mcp__meta-ads__* tool not named below is blocked,
so tools Meta adds later stay blocked until reviewed and added here.
Exit 2 = block the call (stderr goes back to Claude).
"""
import json
import sys

PREFIX = "mcp__meta-ads__"
READ_ONLY = {
    "ads_account_get_activity_logs",
    "ads_catalog_event_source_get",
    "ads_catalog_event_source_get_catalogs",
    "ads_catalog_event_source_get_health",
    "ads_catalog_event_source_get_recommendations",
    "ads_catalog_get_businesses",
    "ads_catalog_get_data_sources",
    "ads_catalog_get_diagnostics",
    "ads_catalog_get_dynamic_ads_health",
    "ads_catalog_get_feed_rules",
    "ads_catalog_get_product_feed_upload_sessions",
    "ads_catalog_get_suggested_filter",
    "ads_catalog_list_catalogs",
    "ads_catalog_list_dpa_eligible_catalogs",
    "ads_catalog_list_partner_integrations",
    "ads_catalog_list_product_feeds",
    "ads_catalog_list_product_sets",
    "ads_catalog_list_products",
    "ads_experiment_abtest_get_test",
    "ads_experiment_check_eligibility",
    "ads_experiment_lift_get_test",
    "ads_experiment_list_tests",
    "ads_get_ad_account_custom_audiences",
    "ads_get_ad_account_pages",
    "ads_get_ad_accounts",
    "ads_get_ad_entities",
    "ads_get_ad_images",
    "ads_get_ad_preview",
    "ads_get_ad_preview_screenshot",
    "ads_get_ad_videos",
    "ads_get_async_result",
    "ads_get_creative_ads",
    "ads_get_creatives",
    "ads_get_custom_audience",
    "ads_get_custom_audience_adsets",
    "ads_get_customconversions",
    "ads_get_dataset_details",
    "ads_get_dataset_quality",
    "ads_get_dataset_stats",
    "ads_get_datasets",
    "ads_get_errors",
    "ads_get_field_context",
    "ads_get_help_article",
    "ads_get_ig_accounts",
    "ads_get_ig_media",
    "ads_get_opportunity_score",
    "ads_get_pages_for_business",
    "ads_get_user_pages",
    "ads_insights_advertiser_context",
    "ads_insights_anomaly_signal",
    "ads_insights_auction_ranking_benchmarks",
    "ads_insights_industry_benchmark",
    "ads_insights_performance_trend",
    "ads_insights_performance_vs_peers",
    "ads_library_search",
    "ads_pixel_event_read",
    "ads_pixel_parameter_read",
}

try:
    tool = json.load(sys.stdin).get("tool_name", "")
except Exception:
    print("meta-ads-ro hook: unreadable input, blocking", file=sys.stderr)
    sys.exit(2)

if tool.startswith(PREFIX) and tool[len(PREFIX):] not in READ_ONLY:
    print(
        f"Blocked: {tool} is not on the read-only Meta Ads allowlist. "
        "The Discord session can only read Meta Ads (campaigns, insights, "
        "creatives, audiences). Changes must be made by the owner in Ads "
        "Manager or a normal Claude session.",
        file=sys.stderr,
    )
    sys.exit(2)
sys.exit(0)
