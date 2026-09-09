# Conversation naming verification

Verified on PHP 8.5.8 with PHPUnit 13.3.3.

## Local dependency setup

The initially installed Neuron Interaction package lacks
`ConcurrentCommandInterface`, which this repository already uses at baseline
`a94437d21a7d264590f26a9ceecd77371d7ca6e7`. Baseline PHPStan reported five errors
related to that missing contract; the 21 focused Conversation tests passed.

For compatible integration verification, the original
`vendor/asterixcapri/neuron-interaction` directory was moved to
`/tmp/neuron-tui-interaction-before-naming-a94437d` and replaced with a symlink
to `/home/asterix/asterixcapri/neuron-interaction`. That checkout was clean at
commit `847405168179ef418a5154a701fe38396d475a68`.
The symlink remains in the local environment. Neither Composer constraints nor
the lock file changed. No dependency release or push was performed.

## Checks

- `vendor/bin/phpunit tests/Conversation/TurnRunnerTest.php`:
  5 tests, 10 assertions passed.
- `vendor/bin/phpunit tests/Conversation/TurnQueueTest.php`:
  7 tests, 18 assertions passed.
- `vendor/bin/phpunit tests/Conversation/SubmissionParserTest.php`:
  9 tests, 24 assertions passed.
- `vendor/bin/phpunit tests/View/ConversationViewTest.php`:
  10 tests, 22 assertions passed.
- `vendor/bin/phpstan analyse --no-progress --memory-limit=512M`:
  no errors.
- `vendor/bin/phpunit`: 217 tests, 950 assertions passed.
- `git diff --check`: passed.
- Active source and tests searched for old class names, namespace and method
  calls: no stale implementation references. Historical research was unchanged.

Existing behavioral tests were adapted to the renamed interfaces. No new test
seams, interfaces, state accessors or test-only hooks were added.
