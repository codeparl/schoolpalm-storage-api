# Architecture

The package separates provider metadata, configured connections, runtime filesystem adapters, and context. It does not create a storage-settings database or depend on SchoolPalm models.

## Philosophy

Storage is an infrastructure detail, but school context and access policy are application concerns. The package provides a stable boundary between those concerns:

- Consumers depend on `DocumentStorage`, not a provider SDK or a concrete disk.
- The host supplies context and resolves school-owned connection configuration from its existing settings source.
- The registry exposes trusted provider metadata for validation and generic settings forms. Metadata describes a provider; it does not create, authorize, or select a school's connection.
- The filesystem adapter translates common operations to a provider while capabilities and explicit exceptions communicate important differences.
- Credentials remain owned by the host's settings repository. OAuth routes, state validation, consent UI, school/user authorization, and encryption remain host responsibilities.

The goal is not to make every cloud service look identical in every detail. It is to give callers a consistent operation surface while exposing real constraints (such as URL support, path semantics, and physical-path availability) instead of hiding them.

## Main components

- `DocumentStorage` is the consumer-facing file API.
- `StorageContextProvider` optionally supplies an immutable `StorageContext`; an empty context is valid.
- `StorageSettingsProvider` resolves context settings. `StorageSettingsRepository` adds named connections and invalidation.
- `StorageProviderRegistry` holds trusted provider implementations and their field/capability definitions.
- `DiskBuilder` validates a resolved provider configuration, converts it to Laravel filesystem configuration, and caches the adapter per context/connection.
- `DocumentPathResolver` validates relative paths and applies tenant/school namespaces.
- `StorageApiServiceProvider` binds the standalone defaults and publishes `config/storage-api.php`.

## Resolution and lifecycle

For each operation, the service resolves its explicit context first, otherwise the optional bound context provider. Explicit contexts are immutable per `DocumentStorage` instance and override automatic context. It then selects the named connection or application default, resolves its provider from the registry, validates and maps configuration, builds/reuses the filesystem adapter, namespaces the relative path, and performs the operation.

If no context-specific settings exist, a settings provider may return an explicit application-level default. It must never return a different tenant or school's settings. Named connections do not silently fall back to the default connection.

Adapter cache keys include the full context identity and connection name. A keyed configuration signature forces a rebuild when settings change. Call `DocumentStorage::invalidate()` after settings writes; custom repositories should also invalidate their own settings cache. No global Laravel disk cache is flushed.

## Provider definition versus connection versus destination

- A **provider definition** is trusted package/application code: ID, driver mapping, capabilities, and field schema.
- A **connection** is configuration for one provider, optionally scoped to a context.
- A **destination/purpose rule** is a host-application policy selecting a connection for an operation category.

The core package resolves connections, not purpose policies. Host code may choose `forConnection('exports')` or another connection based on its own authorized policy.

## Integration philosophy

The package uses Laravel's container and filesystem contracts to fit naturally into Laravel applications, while keeping dependencies on SchoolPalm products optional:

- **Laravel:** `StorageApiServiceProvider` registers the API, provider registry, OAuth service, and filesystem factory. A Laravel filesystem disk can be selected directly with `forDisk()`.
- **Flysystem:** local and built-in cloud drivers are implemented as Flysystem adapters. Google Drive, Dropbox, and OneDrive adapters are package requirements and do not need manual Laravel driver registration. S3 remains an optional host dependency.
- **Settings packages:** `StorageSettingsProvider` and `StorageSettingsRepository` are the persistence seam. The optional `AppSettingsStorageSettingsProvider` bridges the `app-settings.manager` binding at runtime without forcing `schoolpalm/app-settings` into every installation.
- **SchoolPalm application:** a host adapter maps its active tenant/school and existing authorization model to `StorageContext`, loads the correct connection, and owns settings changes and UI.
- **Document workflows:** `DocumentContent` and `StorageWorkspaceInterface` let document producers and batch workflows interact without requiring direct physical paths in the general file API. The batch-workspace extension deliberately requires a local provider with a physical path and tenant/school context.

This is an integration seam, not an assumption about a specific SchoolPalm database schema or route layout. See [SchoolPalm integration](schoolpalm-integration.md), [usage](usage.md), and [settings integration](settings-integration.md).

## Extension boundaries

School-specific settings, authorization, UI, encryption, tenant discovery, and purpose routing belong to the host application. Provider code is installed and registered as trusted Laravel code; values stored in settings can never name arbitrary PHP classes.

See [provider development](provider-development.md), [settings integration](settings-integration.md), and [context integration](context-integration.md).
