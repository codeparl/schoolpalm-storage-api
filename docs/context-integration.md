# Context integration

Context is optional and does not depend on HTTP requests, a session, or SchoolPalm models.

## Automatic context

Bind an implementation of `StorageContextProvider`:

```php
use SchoolPalm\StorageApi\Contracts\StorageContextProvider;
use SchoolPalm\StorageApi\Settings\StorageContext;

final class CurrentStorageContext implements StorageContextProvider
{
    public function current(): ?StorageContext
    {
        return new StorageContext(
            applicationId: app('current.application')->id(),
            tenantId: app('current.tenant')?->id(),
            schoolId: app('current.school')?->id(),
        );
    }
}
```

```php
$this->app->bind(StorageContextProvider::class, CurrentStorageContext::class);
```

When no provider is bound, the package uses `NullStorageContextProvider`. Application-level storage continues to work.

## Explicit background-job context

Explicit context takes precedence over automatically resolved context. Pass it at the operation boundary in queue jobs, commands, scheduled work, and tests:

```php
$storage = app(\SchoolPalm\StorageApi\Contracts\DocumentStorage::class)
    ->forStorageContext(new StorageContext(
        tenantId: $job->tenantId,
        schoolId: $job->schoolId,
    ));

$storage->put('exports/data.csv', $stream);
```

For backwards compatibility, `forContext($tenantId, $schoolId)` remains available. `forStorageContext()` supports application and organization identifiers too.

## Path scoping

Unscoped paths are relative to the selected disk root. Tenant/school paths are prefixed deterministically; for example `tenant-x/schools/school-y/reports/a.csv`. Other context dimensions are included when supplied. `secure-docs` workspace paths require matching tenant and school context. Absolute paths, traversal segments, and mismatched workspace prefixes are rejected.

Settings caches include the full context identity. A settings provider must return only the requested context's connection or an explicitly defined application-level fallback.

For complete file-operation examples and background job patterns, see [usage](usage.md). For mapping SchoolPalm tenant resolution and settings to these contracts, see [SchoolPalm integration](schoolpalm-integration.md).
