# History naming verification

Verified on PHP 8.5.8 with PHPUnit 13.3.3, against baseline
`9741318f490985255a4387cc9e3d326c34c4a762`.

## Local dependency setup

The existing `vendor/asterixcapri/neuron-interaction` symlink points to
`/home/asterix/asterixcapri/neuron-interaction`, clean at commit
`847405168179ef418a5154a701fe38396d475a68`. Its
`src/Command/ConcurrentCommandInterface.php` is present. This is the compatible
local integration dependency established during the preceding Conversation
refactor; the checks below use it, not a fresh installation of the locked
package. Neither the dependency setup, Composer constraints nor lock file was
changed for this work.

## Checks

- `vendor/bin/phpunit tests/History/HistoryProjectionTest.php`: 15 tests,
  36 assertions passed before and after the refactor.
- `vendor/bin/phpunit tests/TuiTest.php`: 113 tests, 591 assertions passed.
- `vendor/bin/phpunit tests/Conversation/TurnRunnerTest.php`: 5 tests,
  10 assertions passed.
- `vendor/bin/phpunit`: 217 tests, 950 assertions passed.
- `vendor/bin/phpstan analyse --no-progress --memory-limit=512M`: no errors
  at baseline, after implementation, and after the full suite.
- `git diff --cached --check`: passed.
- Active source and tests searched for stale renamed types, methods and
  parameters: no stale references. Historical research remains unchanged.

Existing behavioral assertions were retained and adapted to the renamed types.
No new test seam, accessor or test-only hook was introduced. Diff inspection
confirmed that matching, filtering, ordering, formatting and return contracts
remain unchanged.

## Separate follow-up candidates

- An unmatched result without a call ID registers a position available to a
  later same-name result.
- Unavailable historical timing is displayed as less than one second.

Both behaviors are preserved, not resolved by this naming refactor.
