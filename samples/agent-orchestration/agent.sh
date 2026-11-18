#!/usr/bin/env bash
# Launch a Codex agent in its own git worktree: tools/agent.sh <area> [model] [effort]
# Worktree: <repo>/../lunae-<area> on branch agent/<area>, branched from the current HEAD
# (the directory name is the label agenthud shows). Prompt: docs/prompts/<area>.md.
# Log: ~/Documents/Claude/codex-runs/lunae-theme-<area>.log
# The agent must not run git; Claude reviews, commits and merges its branch afterwards.
set -euo pipefail
area=$1; model=${2:-gpt-6-astra}; effort=${3:-high}
repo=$(cd "$(dirname "$0")/.." && pwd)
wt=$(cd "$repo/.." && pwd)/lunae-$area
log=$HOME/Documents/Claude/codex-runs/lunae-theme-$area.log
if [ ! -d "$wt" ]; then
  git -C "$repo" worktree add -q "$wt" -b "agent/$area" HEAD
fi
# data/images is gitignored, so share the scraped photos with later agents via a copy
if [ -d "$repo/data/images" ] && [ ! -e "$wt/data/images" ]; then
  mkdir -p "$wt/data" && cp -a "$repo/data/images" "$wt/data/images"
fi
cd "$wt"
nohup codex exec -p claude -m "$model" -c model_reasoning_effort="$effort" --skip-git-repo-check -C "$wt" \
  "Your full task is in docs/prompts/$area.md in the current directory. Read it and docs/DESIGN.md, then do the work end-to-end." \
  > "$log" 2>&1 &
echo "started $area pid $! in $wt, log $log"
