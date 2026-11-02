#!/bin/sh
# Run in /opt/conwix. Stack lifetime is independent of customer availability.
set -eu
C=${RESEARCH_COMPOSE:-docker compose -f docker-compose.research.prod.yml}
state() {
    if ! environment=$($C config --environment 2>/dev/null); then
        echo 'research: configuration unavailable' >&2
        return 2
    fi
    flag=$(printf '%s\n' "$environment" | sed -n 's/^RESEARCH_STACK_ENABLED=//p')
    case "$flag" in
        true) return 0 ;;
        false|'') return 1 ;;
        *) echo 'research: invalid stack switch' >&2; return 2 ;;
    esac
}
rc=0
state || rc=$?
if [ "${1:-}" = --enabled ]; then
    exit "$rc"
fi
[ "$rc" = 0 ] || exit "$rc"
exec $C --profile research "$@"
