#!/usr/bin/env bash
# Single quality gate: PHPStan (level max) + PHPUnit unit suite.
#
# The default run is self-contained: PHP and composer, nothing else.
#
# With --integration the contract suite additionally runs against a LIVE
# gateway. That needs a local checkout of the gateway service (not part of
# this repository) plus its Postgres/Redis; the suite boots `php -S` from
# the gateway's public/ with a stub project config. Paths and connection
# settings come from PUSHCENTER_GATEWAY_DIR / PUSHCENTER_TEST_* .
set -euo pipefail

cd "$(dirname "$0")/.."

PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"

RUN_INTEGRATION=0
for arg in "$@"; do
    case "$arg" in
        --integration) RUN_INTEGRATION=1 ;;
        *) echo "Unknown option: $arg (supported: --integration)" >&2; exit 2 ;;
    esac
done

if [ ! -d vendor ]; then
    echo "==> composer install"
    "$COMPOSER_BIN" install --no-interaction
fi

echo "==> phpstan (level max)"
"$PHP_BIN" vendor/bin/phpstan analyse --no-progress --memory-limit=1G

echo "==> phpunit (unit)"
"$PHP_BIN" vendor/bin/phpunit --testsuite unit

if [ "$RUN_INTEGRATION" = "1" ]; then
    echo "==> phpunit (integration, live gateway over php -S)"
    "$PHP_BIN" vendor/bin/phpunit --testsuite integration
fi

echo "OK: all checks passed"
