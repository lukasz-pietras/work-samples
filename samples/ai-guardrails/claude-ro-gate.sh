#!/bin/sh
# Forced command for the read-only Claude key (Discord session on the owner's PC).
# authorized_keys: restrict,command="/usr/local/bin/claude-ro-gate" ssh-ed25519 ... claude-discord-ro
# Only whitelisted read-only actions; arguments are strictly validated; everything else denied + logged.
LUNAE=/home/debian/lunae
LIB=/usr/local/lib/claude-ro
deny() { logger -t claude-ro "DENIED: $SSH_ORIGINAL_COMMAND"; echo "claude-ro: command not allowed" >&2; exit 1; }
match() { printf '%s' "$1" | grep -Eqx -- "$2"; }
set -f
# shellcheck disable=SC2086
set -- $SSH_ORIGINAL_COMMAND
cmd=$1; [ $# -gt 0 ] && shift
case "$cmd" in
  wp-mcp)   # WooCommerce MCP as WP user claude-mcp (mu-plugin lunae-mcp-readonly.php: readonly abilities only)
    [ $# -eq 0 ] || deny
    logger -t claude-ro "wp-mcp"
    cd "$LUNAE" && exec docker compose run --rm -T cli wp mcp-adapter serve --server=mcp-adapter-default-server --user=claude-mcp 2>/dev/null ;;
  smtp-logs)   # smtp-logs [term|-] [limit]
    term=${1:--}; limit=${2:-30}
    [ $# -le 2 ] && match "$term" '[A-Za-z0-9@._+#-]{1,64}' && match "$limit" '[0-9]{1,3}' || deny
    logger -t claude-ro "smtp-logs $term $limit"
    cd "$LUNAE" && exec docker compose run --rm -T cli wp eval-file - list "$term" "$limit" < "$LIB/smtp-logs.php" 2>/dev/null ;;
  smtp-log)    # smtp-log <id>
    [ $# -eq 1 ] && match "$1" '[0-9]{1,9}' || deny
    logger -t claude-ro "smtp-log $1"
    cd "$LUNAE" && exec docker compose run --rm -T cli wp eval-file - show "$1" < "$LIB/smtp-logs.php" 2>/dev/null ;;
  *) deny ;;
esac
