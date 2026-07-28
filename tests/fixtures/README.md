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
the other way around. To verify that the copies have not drifted, run the suite
against a contract checkout:

```bash
scripts/check.sh --contract-drift=/path/to/contract
# equivalently: PUSHCENTER_CONTRACT_DIR=/path/to/contract vendor/bin/phpunit --testsuite unit
```

**What each run actually guarantees.** Be precise about this — the two modes
protect against different things:

| | default run (vendored copies) | `--contract-drift` (contract checkout) |
| --- | --- | --- |
| client honours every fixture it is pointed at | yes | yes |
| new `invalid/` fixture upstream is noticed | no — the glob only sees local files | yes, via `FixtureParityTest` and the dynamic `invalid/` enumeration |
| new `valid/` fixture upstream is noticed | no | yes, via `FixtureParityTest` (name-set comparison) |
| local fixture deleted upstream is noticed | no | yes, via `FixtureParityTest` |
| edited CONTENT of an existing fixture | no | yes — the suites replay the upstream bytes |

Two gaps remain by design, and no fixture mechanism closes them:

- a new *valid* fixture is only asserted set-wise; `FixtureRequestSerializationTest`
  and `BroadcastRequestTest` still name their fixtures one by one, so a new one
  is reported as missing coverage rather than exercised automatically. Add the
  case by hand;
- a new validation RULE without a new fixture is invisible to this repository
  altogether. Contract changes are reviewed, not inferred.

Because there is no CI (see the repository `CLAUDE.md`), none of this runs by
itself: `--contract-drift` is a manual pre-release step.
