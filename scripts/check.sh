#!/usr/bin/env bash
# Single quality gate: PHPStan (level max) + PHPUnit unit suite.
#
# The default run is self-contained: PHP and composer, nothing else. It runs
# against the golden fixtures vendored in tests/fixtures/ and therefore proves
# that the client honours THOSE copies — not that they still match the contract.
#
# --contract-drift[=DIR] re-runs the unit suite against a checkout of the
# contract (DIR, or $PUSHCENTER_CONTRACT_DIR). That run additionally compares
# the vendored fixture names with the upstream ones in both directions, which is
# the only check that notices a fixture ADDED upstream. Run it before a release
# and after every contract change.
#
# --integration runs the contract suite against a LIVE gateway. That needs a
# local checkout of the gateway service (not part of this repository) plus its
# Postgres/Redis; the suite boots `php -S` from the gateway's public/ with a
# stub project config. Paths and connection settings come from
# PUSHCENTER_GATEWAY_DIR / PUSHCENTER_TEST_* — PUSHCENTER_GATEWAY_DIR has no
# default and must be set explicitly.
set -euo pipefail

cd "$(dirname "$0")/.."

PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"

USAGE='Usage: scripts/check.sh [--contract-drift[=DIR]] [--integration]'

RUN_INTEGRATION=0
RUN_DRIFT=0
DRIFT_DIR="${PUSHCENTER_CONTRACT_DIR:-}"
for arg in "$@"; do
    case "$arg" in
        --integration) RUN_INTEGRATION=1 ;;
        --contract-drift) RUN_DRIFT=1 ;;
        --contract-drift=*) RUN_DRIFT=1; DRIFT_DIR="${arg#*=}" ;;
        *) echo "Unknown option: $arg" >&2; echo "$USAGE" >&2; exit 2 ;;
    esac
done

if [ "$RUN_DRIFT" = "1" ]; then
    if [ -z "$DRIFT_DIR" ]; then
        echo "--contract-drift needs a contract checkout: pass --contract-drift=/path/to/contract" >&2
        echo "or export PUSHCENTER_CONTRACT_DIR. The contract is not part of this repository." >&2
        exit 2
    fi
    if [ ! -d "$DRIFT_DIR/fixtures" ]; then
        echo "No fixtures/ directory under '$DRIFT_DIR' — is it a contract checkout?" >&2
        exit 2
    fi
fi

if [ "$RUN_INTEGRATION" = "1" ] && [ -z "${PUSHCENTER_GATEWAY_DIR:-}" ]; then
    echo "--integration needs a local gateway checkout: export PUSHCENTER_GATEWAY_DIR=/path/to/gateway." >&2
    echo "The gateway is not part of this repository and has no default location." >&2
    exit 2
fi

if [ ! -d vendor ]; then
    echo "==> composer install"
    "$COMPOSER_BIN" install --no-interaction
fi

echo "==> phpstan (level max)"
"$PHP_BIN" vendor/bin/phpstan analyse --no-progress --memory-limit=1G

echo "==> phpunit (unit, vendored fixtures)"
"$PHP_BIN" vendor/bin/phpunit --testsuite unit

if [ "$RUN_DRIFT" = "1" ]; then
    echo "==> phpunit (unit, upstream fixtures from $DRIFT_DIR)"
    PUSHCENTER_CONTRACT_DIR="$DRIFT_DIR" "$PHP_BIN" vendor/bin/phpunit --testsuite unit
fi

if [ "$RUN_INTEGRATION" = "1" ]; then
    echo "==> phpunit (integration, live gateway over php -S)"
    "$PHP_BIN" vendor/bin/phpunit --testsuite integration
fi

echo "OK: all checks passed"
