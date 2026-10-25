# Testing

The package suite uses PHPUnit 11 and Orchestra Testbench. It does not need SchoolPalm production models, database settings, cloud credentials, or internet access. Composer installs the default provider adapters so adapter construction, OAuth handling, path behavior, and mocked API calls are covered locally.

```sh
composer test
composer lint
composer validate --no-check-publish
```

Use Laravel's `Storage::fake()` and select its registered disk with `forDisk()` for deterministic operations:

```php
Storage::fake('local');
$storage = app(DocumentStorage::class)->forDisk('local');
$storage->put('sample.txt', 'content');
$this->assertSame('content', $storage->get('sample.txt'));
```

Use in-memory `StorageSettingsProvider` test doubles for context and settings behavior. To test S3 configuration mapping, call `StorageProviderRegistry::toFilesystemConfig()`; no adapter or credentials are needed. Optional integration tests for a Flysystem adapter should be kept separate and use a local emulator or dedicated credentials in CI secrets.

`CloudStorageProviderTest` tests the OAuth URL/token shapes, expiry refresh and context-scoped persistence, sanitized authorization failures, real adapter construction, Google file-ID metadata access through a provider fake, Dropbox cursor pagination through mocked HTTP responses, and OneDrive drive/path write behavior through a fake Graph client. No live cloud calls are made.

Optional tenant-level live tests are intentionally not part of `composer test`. To add them, place them in a separately selected test suite, use dedicated least-privilege test accounts, and inject credentials through CI secrets. Never place real tokens in fixtures or test output.
