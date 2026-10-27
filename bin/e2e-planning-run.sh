#!/bin/sh
# Runs the existing browser suite with one local synthetic cohort feed.
# The PHP helper refuses every DB except app and verifies its own company.
set -eu
cd "$(dirname "$0")/.."

# The OS releases this lock after a killed run's remaining processes exit.
# Keep the inode: unlinking a locked file would permit a second lock owner.
exec 9>api/var/e2e-sales-planning-run.flock
if ! flock -n 9; then
    echo 'e2e-planning-run: another planning browser run is active; refusing' >&2
    exit 1
fi
feed_pid=
cleanup() {
    result=$?
    trap - EXIT INT TERM
    touch api/var/e2e-sales-planning-stop
    if [ -n "$feed_pid" ]; then
        if ! wait "$feed_pid"; then
            echo 'e2e-planning-run: synthetic feed failed; see var/e2e-planning-feed.log' >&2
            result=1
        fi
    fi
    if ! docker compose exec -T -e APP_ENV=dev -e CONWIX_E2E_PLANNING=1 php-cli \
        php tests/Support/SalesPlanning/e2e-cohort.php cleanup; then
        result=1
    fi
    exit "$result"
}
trap cleanup EXIT
trap 'exit 1' INT TERM

docker compose exec -T -e APP_ENV=dev -e CONWIX_E2E_PLANNING=1 php-cli \
    php tests/Support/SalesPlanning/e2e-cohort.php cleanup
# The shared seed also replaces sandbox identities used by the browser suite,
# so it must run under the same lock as the planning fixture and Playwright.
sh bin/e2e-seed.sh
docker compose exec -T -e APP_ENV=dev -e CONWIX_E2E_PLANNING=1 php-cli \
    php tests/Support/SalesPlanning/e2e-cohort.php seed \
    "$(sed -n '1p' var/e2e-user-credentials)"
docker compose exec -T -e APP_ENV=dev -e CONWIX_E2E_PLANNING=1 php-cli \
    php tests/Support/SalesPlanning/e2e-cohort.php wait >var/e2e-planning-feed.log 2>&1 &
feed_pid=$!

docker compose exec -T \
    -e E2E_COMPANY_ID="$(cat var/e2e-company-id)" \
    -e E2E_SECOND_COMPANY_ID="$(cat var/e2e-second-company-id)" \
    -e E2E_USER_EMAIL="$(sed -n '1p' var/e2e-user-credentials)" \
    -e E2E_USER_PASSWORD="$(sed -n '2p' var/e2e-user-credentials)" \
    -e E2E_ADMIN_EMAIL="$(sed -n '1p' var/e2e-admin-credentials)" \
    -e E2E_ADMIN_PASSWORD="$(sed -n '2p' var/e2e-admin-credentials)" \
    playwright npx playwright test "$@"

# A suite with the new scenario silently skipped must not count as proof.
if [ ! -f api/var/e2e-sales-planning-done.json ]; then
    echo 'e2e-planning-run: late-outcome scenario did not complete' >&2
    exit 1
fi
