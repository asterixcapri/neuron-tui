# SessionStore integration validation

Validated on 2026-09-06 with PHP 8.5.8. Both Composer graphs lock
`asterixcapri/neuron-interaction` on branch
`dev-feat/session-configuration-stores` at
`25f8c3a05f0ad8b2547af1d6bf798e52355da58b`.

From the repository root and separately from `examples/`, ran:

```sh
composer update asterixcapri/neuron-interaction --no-interaction
php -r 'require "vendor/autoload.php"; echo Composer\InstalledVersions::getReference("asterixcapri/neuron-interaction"), PHP_EOL, (new ReflectionClass(NeuronInteraction\Session\SessionStore::class))->getFileName(), PHP_EOL;'
composer test
composer stan
```

Only neuron-interaction changed in each lockfile. InstalledVersions returned
the exact revision above in both processes. Reflection confirmed that root
loads `vendor/asterixcapri/neuron-interaction/src/Session/SessionStore.php`
and the demo loads its own corresponding file under `examples/vendor/`.
No machine-specific dependency paths or release publication were needed.

| Graph | PHPUnit | PHPStan |
| --- | --- | --- |
| TUI | 217 tests, 955 assertions passed | No errors |
| Demo | 2 tests, 14 assertions passed | No errors |

The suites use VirtualTerminal and Agent/provider fixtures without credentials
or paid requests. Coverage includes scoped Session selection, Clear/Resume,
persistent reopening, preservation of supplied History, local identity,
missing selections, cancellation, busy Command policy, independent input
history, and model switching with History transfer.

During ticket 04 implementation, an actual demo PTY smoke ran `php demo.php`
with an empty temporary `.env`: startup rendered the ready composer, `/model`
opened its Picker, selection displayed the model-change notice, and `/exit`
ended with code 0. No ordinary message or provider request was submitted;
temporary environment and storage files were removed. That smoke used library
revision `4eb4ad670d07e693c7c1d3c0e01cbec5de5435b4`; the automated checks above
were rerun against the final revision after review fixes. Live provider
responses were not tested.
