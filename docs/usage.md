# Usage guide

This guide covers ordinary storage operations and the choices an application makes around context, connections, streaming, and provider capabilities. For the host-owned configuration and OAuth lifecycle, see [SchoolPalm integration](schoolpalm-integration.md) and [provider configuration](providers.md).

## Resolve storage

Inject the contract in application services and jobs. The facade is convenient in small call sites:

```php
use SchoolPalm\StorageApi\Contracts\DocumentStorage;

final class ReportArchive
{
    public function __construct(private readonly DocumentStorage $storage) {}

    public function save(string $name, string $csv): void
    {
        $this->storage->put('reports/'.$name, $csv);
    }
}
```

```php
use SchoolPalm\StorageApi\Facades\StorageApi;

StorageApi::put('reports/term-1.csv', $csv);
$csv = StorageApi::get('reports/term-1.csv');
```

When no storage connection is configured, the package uses Laravel's `filesystems.default`. Configure `storage-api.default_connection` and `storage-api.connections` to opt into provider-neutral named connections; alternatively bind a settings provider for dynamic connections. See [provider configuration](providers.md).

## Choose a disk or named connection

`forDisk()` explicitly selects an existing Laravel disk. Use it when the application intentionally wants Laravel's existing disk configuration:

```php
$storage = app(DocumentStorage::class)->forDisk('private');
$storage->put('invoices/2026/receipt.pdf', $contents);
```

`forConnection()` selects a named connection returned by the active `StorageSettingsProvider` or settings repository:

```php
$storage = app(DocumentStorage::class)
    ->forStorageContext($context)
    ->forConnection('school-documents');

$storage->put('policies/attendance.pdf', $contents);
```

The selection methods return new storage instances. They do not mutate the instance they are called on. A named connection that does not exist is an explicit configuration error; it does not silently switch to a different school's default.

## Context for a school

Use the context already resolved by the host, or pass a context explicitly:

```php
use SchoolPalm\StorageApi\Contracts\DocumentStorage;
use SchoolPalm\StorageApi\Settings\StorageContext;

$context = new StorageContext(
    applicationId: 'schoolpalm',
    tenantId: $tenantId,
    schoolId: $schoolId,
);

$schoolStorage = app(DocumentStorage::class)
    ->forStorageContext($context)
    ->forConnection('school-documents');

$schoolStorage->put('policies/attendance.pdf', $pdfStream);
```

With an explicit or automatic context, the path resolver prefixes ordinary relative paths with context segments. For example, tenant `tenant-a` and school `school-9` are scoped under `tenant-a/schools/school-9/`. Applications may bind `StorageContextProvider` to resolve the current context automatically; queue jobs should pass their tenant and school identifiers explicitly rather than relying on a request or session.

Context namespacing protects against accidental path collisions; it is not authorization. The host must authorize access to the school and connection before issuing storage operations. A settings repository must never substitute another tenant's settings.

## Store and retrieve files

Paths passed to `DocumentStorage` are relative to the selected storage connection. Do not prepend the tenant prefix yourself. Absolute paths, drive-letter paths, traversal segments (`..`), empty paths, and mismatched workspace paths are rejected.

```php
$storage->put('students/2026/class-list.csv', $csv);

if ($storage->exists('students/2026/class-list.csv')) {
    $length = $storage->size('students/2026/class-list.csv');
    $mimeType = $storage->mimeType('students/2026/class-list.csv');
    $modified = $storage->lastModified('students/2026/class-list.csv');
}

$csv = $storage->get('students/2026/class-list.csv');
$storage->delete('students/2026/class-list.csv');
```

`get()` returns the full file as a string. Use it only for suitably small objects. For large files, upload/download by path or use a stream.

### Upload an incoming file

The host's HTTP layer validates and authorizes the upload. The API receives the resulting local path and streams it:

```php
$localPath = $uploadedFile->getRealPath();
if ($localPath === false) {
    throw new RuntimeException('The uploaded file is not available.');
}

$storage->putFromPath(
    'submissions/'.$submissionId.'/'.$uploadedFile->getClientOriginalName(),
    $localPath,
);
```

Validate and normalize user-supplied filenames in the host application before using them in a path. Do not treat a client-provided school ID, connection name, folder ID, or path as authorization.

### Stream explicitly

The caller owns streams it supplies and must close them. Streams returned by `readStream()` are also caller-owned:

```php
$source = fopen($localPath, 'rb');
if ($source === false) {
    throw new RuntimeException('The source file could not be opened.');
}

try {
    $storage->writeStream('archive/report.csv', $source);
} finally {
    fclose($source);
}

$remote = $storage->readStream('archive/report.csv');
try {
    stream_copy_to_stream($remote, $responseStream);
} finally {
    fclose($remote);
}
```

`putFromPath()` and `copyToPath()` are the simpler path-to-path alternatives and avoid materializing the whole file as a PHP string.

## List and manage directories

```php
$directFiles = $storage->files('reports/2026');
$allFiles = $storage->allFiles('reports/2026');
$directFolders = $storage->directories('reports');
$allFolders = $storage->allDirectories('reports');

$storage->copy('reports/current.csv', 'reports/archive/current.csv');
$storage->move('reports/draft.csv', 'reports/final.csv');
$storage->deleteDirectory('temporary/import-123');
```

Remote storage may implement folders as metadata or path prefixes. A successful `move()` or `copy()` has provider-defined implementation semantics; inspect `capabilities()` before depending on atomicity or server-side copy.

## Check capabilities before optional operations

Capabilities describe the selected connection, not an abstract provider wish list:

```php
$capabilities = $storage->capabilities();

if ($capabilities->supportsTemporaryUrl) {
    $url = $storage->temporaryUrl('reports/2026/report.pdf');
}

if ($capabilities->supportsPhysicalPath) {
    $path = $storage->physicalPath('reports/2026/report.pdf');
}

if ($capabilities->supportsStreamWrite && $capabilities->chunkSize !== null) {
    // The connection supports streaming and reports its configured chunk size.
}
```

Google Drive, Dropbox, and OneDrive do not advertise public or temporary URLs. Do not return an upstream download URL as a substitute for an authorized temporary link. S3 and local URL support depends on their connection configuration. Unsupported optional operations raise `UnsupportedStorageOperationException`; provider operation failures raise sanitized storage exceptions.

For an overview of actual provider limitations, see [provider configuration](providers.md) and [the API reference](api.md).

## Use in a queued job

Resolve tenant/school IDs from the trusted job payload or domain record, then create an explicit scoped storage instance:

```php
use SchoolPalm\StorageApi\Contracts\DocumentStorage;
use SchoolPalm\StorageApi\Settings\StorageContext;

final class ArchiveExport
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $schoolId,
        public readonly string $localExportPath,
    ) {}

    public function handle(DocumentStorage $storage): void
    {
        $schoolStorage = $storage
            ->forStorageContext(new StorageContext(
                tenantId: $this->tenantId,
                schoolId: $this->schoolId,
            ))
            ->forConnection('school-documents');

        $schoolStorage->putFromPath(
            'exports/annual-report.csv',
            $this->localExportPath,
        );
    }
}
```

The important point is to restore context from trusted job data and not depend on HTTP middleware in a worker. In a real job, validate the local source path and choose the remote destination from domain data rather than accepting an arbitrary client path.

## Errors and application responses

The storage layer signals invalid configuration, unsupported providers/operations, invalid paths, authorization failures, and provider failures with package exceptions. Catch exceptions at an application boundary when a domain response is needed, but do not convert every exception into “file not found” or a success-shaped result.

Do not expose provider response bodies or OAuth tokens in API error messages. For an expired/revoked cloud authorization, notify the authorized school administrator that reconnection is needed; do not retry indefinitely. See [security](security.md).

## Connect cloud storage

Installing the package installs the built-in Google Drive, Dropbox, and OneDrive adapters. The host still needs to register OAuth applications, provide callback routes and secure one-time state/PKCE handling, and save tokens into its existing settings repository. Follow [the SchoolPalm integration guide](schoolpalm-integration.md) for the full lifecycle.

S3 is enabled by installing `league/flysystem-aws-s3-v3` in the host. Local and configured Laravel disks can be used without cloud OAuth setup.

## Run tests

```sh
composer test
composer lint
```

The package test suite uses provider fakes and mocked HTTP; it does not need live credentials. See [testing](testing.md).
