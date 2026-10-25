#!/usr/bin/env bash
# Настоящая Make-цель; fake Docker моделирует ответы advisory-сервисов.
set -euo pipefail
cd "$(dirname "$0")/../.."
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
mkdir -p "$work/bin"
cat > "$work/bin/docker" <<'DOCKER'
#!/usr/bin/env bash
set -euo pipefail
if [[ "$*" == 'compose version' ]]; then exit 0; fi
case "$*" in
  *'composer audit'*) scope=composer ;;
  *'/tmp/conwix-root-audit'*) scope=root ;;
  *'/var/www/packages/api-schema'*) scope=schema ;;
  *'/var/www/packages/ui'*) scope=ui ;;
  *'node-extension npm audit'*) scope=extension ;;
  *'node-admin npm audit'*) scope=admin ;;
  *'node-seller npm audit'*) scope=seller ;;
  *) printf 'Unexpected Docker command: %s\n' "$*" >&2; exit 99 ;;
esac
printf '%s\n' "$scope" >> "$AUDIT_CALLS"
if [[ "$scope" == "${FAIL_AUDIT:-}" ]]; then exit 42; fi
DOCKER
chmod +x "$work/bin/docker"
export AUDIT_CALLS="$work/calls"
for scope in composer seller admin extension schema ui root; do
  : > "$AUDIT_CALLS"
  if FAIL_AUDIT="$scope" PATH="$work/bin:$PATH" make audit > "$work/output" 2>&1; then
    printf 'FAIL: %s audit failure must fail make audit\n' "$scope" >&2
    exit 1
  fi
  if ! grep -qx "$scope" "$AUDIT_CALLS"; then
    printf 'FAIL: %s audit was not executed\n' "$scope" >&2
    exit 1
  fi
done
: > "$AUDIT_CALLS"
FAIL_AUDIT='' PATH="$work/bin:$PATH" make audit > "$work/output" 2>&1
for scope in composer seller admin extension schema ui root; do
  grep -qx "$scope" "$AUDIT_CALLS"
done
printf 'PASS: all seven dependency trees audited; every failure propagates\n'
