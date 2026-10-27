# SchoolPalm Storage API

`schoolpalm/storage-api` is the storage boundary for Laravel applications that need to save documents without binding application code to a particular disk or cloud vendor. It offers one `DocumentStorage` API over Laravel disks and the package's Google Drive, Dropbox, and OneDrive integrations, with explicit support for context-specific settings and paths.

The package is designed to be usable by SchoolPalm applications and independently by any compatible Laravel application. SchoolPalm supplies the application context, settings persistence, authorization, and user experience; this package supplies provider discovery, storage operations, path handling, capabilities, and cloud filesystem construction. It does not introduce a storage-settings database, tenant models, HTTP routes, or provider-specific UI.

## Design philosophy

- **A stable storage boundary:** application and domain code asks to read, write, list, or delete a storage-relative path. It does not need to construct S3 requests, call Graph endpoints, or know how Google Drive represents file IDs.
- **Context is explicit and isolated:** a storage operation can use the current application context or a context passed directly by a job or command. The same connection name can resolve to different, separately saved school configurations.
- **Integrate, don't take ownership:** Laravel provides the filesystem layer; Flysystem adapters provide storage drivers; the host application retains ownership of school settings, authorization, routes, encryption, and policies.
- **Capabilities are declared, not guessed:** callers can check whether a connection supports URLs, directories, physical paths, or streaming before choosing an operation. The package does not claim all providers behave like a local disk.
- **Trust code, validate data:** providers are registered trusted PHP implementations. School-saved configuration selects a registered provider; it cannot load a PHP class or install a driver at runtime.
- **Make security and failures visible:** relative paths are validated, credentials are redacted from settings serialization, OAuth refreshes use the host's context-scoped credential store, and unsupported operations raise explicit exceptions.

See [the philosophy and architecture guide](docs/architecture.md) for the resolution lifecycle and [the usage guide](docs/usage.md) for end-to-end examples.

## Features

- Local, Amazon S3/S3-compatible, Google Drive, Dropbox, and Microsoft OneDrive providers.
- Laravel filesystem disks and registered custom providers.
- Optional application, tenant, school, and organization context.
- Explicit context overrides for jobs, commands, and tests.
- Per-context settings repositories and named storage connections.
- Streaming file reads/writes, file management, metadata, and URLs.
- First-party Google Drive, Dropbox, and OneDrive Flysystem integrations with OAuth 2.0.
- Provider field schemas, OAuth permission metadata, validation, secret masking, and metadata seeding hooks.
- Context-scoped connection caching and targeted invalidation.

## Requirements and installation

- PHP 8.2+
- Laravel 11.9.2+ or 12 (or compatible Illuminate filesystem/support packages)
- Composer installs the supported Google Drive, Dropbox, and OneDrive adapters and their SDKs automatically.

```sh
composer require schoolpalm/storage-api
```

Laravel package discovery registers `StorageApiServiceProvider`. Publish the optional config file with:

```sh
php artisan vendor:publish --tag=storage-api-config
```

The package uses `filesystems.default` when no `storage-api` connection is configured. This allows a standalone application to use its normal Laravel filesystem setup.

## Quick start

```php
use SchoolPalm\StorageApi\Facades\StorageApi;

StorageApi::put('reports/term-1.csv', $csvContents);
$contents = StorageApi::get('reports/term-1.csv');
StorageApi::delete('reports/term-1.csv');
```

Paths are relative to the disk. With no context they remain relative to the configured disk root. Tenant and school contexts namespace paths to prevent accidental cross-school collisions.

For full request, upload/download, context, background-job, and error-handling examples, see [usage](docs/usage.md).

## Configure a connection

In `config/storage-api.php`:

```php
return [
    'default_connection' => 'private-files',
    'fallback_disk' => null,
    'connections' => [
        'private-files' => [
            'provider' => 'local',
            'config' => [
                'root' => storage_path('app/private'),
                'visibility' => 'private',
            ],
        ],
    ],
    'context_provider' => null,
    'settings_provider' => null,
    'providers' => [],
];
```

Use `StorageApi::forConnection('private-files')` for a named connection. Use `StorageApi::forDisk('public')` to explicitly select a disk already configured in Laravel's `filesystems.disks`.

Amazon S3 and compatible endpoints use the same `s3` provider. Install `league/flysystem-aws-s3-v3` in the host application to enable it. Google Drive, Dropbox, and OneDrive do not require manual adapter registration. Their account authorization is performed by the host application through the package's OAuth service; the package intentionally does not add HTTP routes or UI.

Cloud connections use the existing settings provider. Implement `StorageOAuthCredentialStore` and configure its class in `storage-api.oauth_credential_store` to save refreshed tokens through the host's encrypted settings system. See [provider configuration](docs/providers.md), [settings integration](docs/settings-integration.md), and [security](docs/security.md).

```php
$registry = app(\SchoolPalm\StorageApi\Providers\StorageProviderRegistry::class);
$providerSchemas = $registry->toArray(); // includes fields, OAuth scopes, and capabilities
```

## Context and dynamic settings

The package defaults to no context. Bind a `StorageContextProvider` for automatic resolution, or pass a `StorageContext` explicitly:

```php
use SchoolPalm\StorageApi\Facades\StorageApi;
use SchoolPalm\StorageApi\Settings\StorageContext;

$schoolStorage = StorageApi::forStorageContext(new StorageContext(
    tenantId: 'tenant-id',
    schoolId: 'school-id',
));

$schoolStorage->put('exports/students.csv', $csvContents);
```

Applications can bind their own `StorageSettingsProvider` or `StorageSettingsRepository`. An optional `AppSettingsStorageSettingsProvider` adapter is provided without making `schoolpalm/app-settings` a package dependency. See [context integration](docs/context-integration.md) and [settings integration](docs/settings-integration.md).

## How this fits into SchoolPalm

In a SchoolPalm deployment, the host application is the composition root:

1. It resolves the active tenant, school, organization, or application and builds a `StorageContext`.
2. It loads that context's selected connection from its existing settings system (for example, SchoolPalm App Settings or an application-owned repository).
3. It authorizes the user or job to use that connection and calls `DocumentStorage`.
4. For OAuth providers, it owns the browser routes, one-time state/PKCE verifier, redirect/callback lifecycle, and user consent experience. This package exchanges OAuth codes and refreshes tokens.
5. It persists initial and rotated credentials through its existing encrypted settings repository, implementing `StorageOAuthCredentialStore` for refresh persistence.

This separation keeps the package reusable and prevents it from creating a competing database or bypassing SchoolPalm's permission model. See the [SchoolPalm integration guide](docs/schoolpalm-integration.md).

The package also fits into other Laravel packages through dependency injection and small contracts. A document-generation package can depend on `DocumentStorage` and `DocumentContent` without depending on an application controller or cloud SDK. If it needs the package's batch workspace extension, `batchWorkspace()` is available for local/physical-path storage with tenant and school context.

## Testing and code style

```sh
composer test
composer lint
composer validate --no-check-publish
```

See [testing](docs/testing.md) for fakes and optional adapter checks.

## Releasing to Packagist

The Composer package name is `schoolpalm/storage-api`; Composer derives releases from Git tags, so the package does not pin a `version` in `composer.json`.

For each release, commit the intended source and documentation changes, create and push a version tag (for example, `v1.0.0`) to the repository listed in the package metadata, then allow Packagist to import the tag. Register the repository with Packagist once and enable its GitHub update hook so future tags are discovered automatically. Consumers can then install a release with:

```sh
composer require schoolpalm/storage-api
```

## Documentation

- [Architecture](docs/architecture.md)
- [Usage guide](docs/usage.md)
- [SchoolPalm and package integration](docs/schoolpalm-integration.md)
- [Provider development](docs/provider-development.md)
- [Settings integration and metadata seeding](docs/settings-integration.md)
- [Provider configuration fields API](docs/provider-configuration-fields.md)
- [Context integration](docs/context-integration.md)
- [Provider configuration](docs/providers.md)
- [Security](docs/security.md)
- [API reference](docs/api.md)
- [Testing](docs/testing.md)
- [Upgrade notes](docs/upgrade.md)
