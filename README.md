# Work samples

Here's what I build with and without AI, at work and outside it.

Most of my work belongs to my employer, so I can't show that code. I describe it first. After that come my own projects, where everything's open and the code is in this repo.

**The page with diagrams:** https://lukasz-pietras.github.io/work-samples/

## At work

I'm the only support engineer at Shelf, an enterprise AI company. Instead of doing every investigation by hand, I built an agent that my colleagues and I talk to in Slack.

- A Slack app passes mentions and DMs to a coordinator agent (Claude, with Codex as a backup) on a dedicated cloud server. For bigger jobs it spins up helper agents.
- It can see Jira, GitHub, CI, AWS and Datadog at engineer level, read-only by default.
- It asks me before production data changes, merges, releases, deploys, deletes and access changes.
- It never posts anything a customer can see. Customer replies are drafts, and a person sends them.

Between Q3 2025, just before I took over, and Q3 2026: average time to resolve went from 24.6 to 3.8 days, median first response from 13.1 hours to 33 minutes, while incoming tickets rose 43.5%. Team numbers from Jira.

## Outside work: lunae.pl

[lunæ](https://lunae.pl) is my partner's lingerie brand. She created it, designs everything and runs the brand. I do the technical side: the shop, the server and the automation.

I built it with Claude Code and Codex agents. They wrote most of the code. I wrote the briefs, made the calls and signed off on everything that went live. Build plus migration took 10 days.

| What | Where |
|---|---|
| Moving the live shop off Shoper: API export, an importer that cleans up a messy export, redirects for every old URL | [`samples/migration`](samples/migration) |
| Six n8n workflows that watch orders, stock, ads, SEO and uptime | [`samples/n8n-workflows`](samples/n8n-workflows) |
| How I run several agents at once: launcher, two briefs, one agent's report | [`samples/agent-orchestration`](samples/agent-orchestration) |
| Keeping my phone assistant read-only: SSH gate, allowlist, read-only shop user | [`samples/ai-guardrails`](samples/ai-guardrails), [`samples/mcp-servers`](samples/mcp-servers) |
| Shipping labels that print themselves | [`samples/label-autoprint`](samples/label-autoprint) |
| A Playwright script for an iPhone bug | [`samples/migration/qa-price-filter.mjs`](samples/migration/qa-price-filter.mjs) |

The files are lifted straight from my private repos, so they read like working code and agent briefs, not like this page. Account IDs are swapped for placeholders, and some comments are in Polish, because the shop is.
