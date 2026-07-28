# Golden fixtures

Request and response examples for the PushCenter gateway HTTP API v1, split
into `valid/` (must be constructible through the client API and serialize
byte-compatibly) and `invalid/` (must be rejected — client-side where the rule
is checkable client-side, by the gateway otherwise).

**Origin.** These files are a vendored copy of the golden fixture set that
accompanies the gateway API contract. They live in this repository so the test
suite is self-contained: `scripts/check.sh` passes on a fresh checkout with
nothing but PHP and composer installed. Domain values (event types, deeplink
targets, UI strings, project names) are illustrative placeholders and carry no
meaning beyond exercising the schema.

**Updating.** The contract is the source of truth; these copies follow it, never
the other way around. To verify that the copies have not drifted, point the
suite at a contract checkout:

```bash
PUSHCENTER_CONTRACT_DIR=/path/to/contract vendor/bin/phpunit --testsuite unit
```

`InvalidFixtureRejectionTest` enumerates `invalid/*.json` dynamically, so a
fixture present upstream but missing here (or vice versa) surfaces as a test
failure rather than silently passing.
