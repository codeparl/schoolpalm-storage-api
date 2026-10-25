# Upgrade notes

## Scaffold to runtime implementation

The package now requires `illuminate/filesystem` in addition to contracts and support. S3 support remains optional; install `league/flysystem-aws-s3-v3` in applications that use it.

## Existing storage contracts

The existing `DocumentStorage` API is retained and extended with explicit full-context, named-connection, file-listing, metadata, upload, and invalidation methods. Custom implementations of `DocumentStorage` must implement these additions. Existing tenant/school calls to `forContext()` remain valid.

The previous path resolver required tenant and school context for every operation. It now permits unscoped application-level storage. Contextual operations continue to prefix paths; absolute paths and traversal segments are rejected.

Unsupported public or temporary URL requests now raise `UnsupportedStorageOperationException` instead of silently returning `null`. Check `capabilities()` before requesting an optional operation.

The former nested `Storage\Providers`, `Storage\Settings`, and storage-settings contract namespaces are retained as aliases to the canonical top-level package types where possible. New integrations should use the contracts and classes documented in [the API reference](api.md).

## Settings migration

Standalone applications may keep using Laravel `filesystems.default`. To opt into named connections, configure `storage-api.default_connection` and `storage-api.connections`. Context-aware applications should bind a `StorageSettingsProvider`/`StorageSettingsRepository`, optionally a `StorageContextProvider`, and call `invalidate()` after updating settings.

No migration or destructive settings rewrite is performed by this package.
