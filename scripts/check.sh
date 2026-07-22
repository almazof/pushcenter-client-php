#!/usr/bin/env bash
# Single quality gate: PHPStan (level max) + PHPUnit unit suite.
#
# With --integration the contract suite runs against a LIVE gateway:
# the suite itself boots `php -S` from ../gateway/public with a stub
# project config; the gateway docker-compose postgres/redis must already
# be up (cd ../gateway && docker compose up -d postgres redis) with
# migrations applied to pushcenter_test. The default run needs no docker.
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
