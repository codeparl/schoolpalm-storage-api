# Provider development

The default `gdrive`, `dropbox`, and `onedrive` providers are implemented with adapters installed by this package and are wired by `CloudFilesystemFactory`; host applications do not register Laravel filesystem drivers for them. Providers are normal, trusted Laravel extensions. Do not accept PHP class names or executable code from school configuration.

## Implement a provider

Implement `SchoolPalm\StorageApi\Contracts\StorageProvider` for additional providers. A convenient starting point is `AbstractStorageProvider`, which applies safe schema defaults, validates fields, and prevents a submitted `driver` or `class` value from selecting an arbitrary implementation.

```php
use SchoolPalm\StorageApi\Contracts\StorageCapabilities;
use SchoolPalm\StorageApi\Providers\AbstractStorageProvider;
use SchoolPalm\StorageApi\Providers\ConfigurationField;
use SchoolPalm\StorageApi\Providers\ProviderDefinition;

final class ArchiveStorageProvider extends AbstractStorageProvider
{
    public function definition(): ProviderDefinition
    {
        return new ProviderDefinition(
            key: 'archive',
            label: 'Company archive',
            driver: 'company-archive',
            fields: [
                ConfigurationField::url('endpoint')->description('Archive API endpoint.'),
                ConfigurationField::string('token')->secret(true),
            ],
            capabilities: StorageCapabilities::generic('archive'),
            description: 'Uses the host-registered company archive Flysystem adapter.',
        );
    }
}
```

For an external provider, the host must register its filesystem driver with Laravel and provide the associated Flysystem adapter. Configure the provider in a service provider before the storage registry is first resolved:

```php
$this->app->make(StorageProviderRegistry::class)
    ->register(new ArchiveStorageProvider());
```

Alternatively, add its trusted class to `storage-api.providers`.

## Schema and validation

`ConfigurationField` supports text/password, URL, number, boolean, select options, safe defaults, and validation constraints. Mark tokens, passwords, and keys as secret. Secret defaults are omitted from schema output. `ProviderDefinition::authorization` adds generic OAuth flow, scope, and authorization endpoint metadata for providers that need consent. `ProviderDefinition::validate()` and `StorageProviderRegistry::validateConfiguration()` report invalid field names only, never submitted values.

For update forms, merge omitted secrets with existing encrypted settings using `mergePreservingSecrets()`. Persistence and OAuth callback routes remain the host's responsibility. The three bundled OAuth providers use `StorageOAuthService` for authorization URLs, code exchange, and token refresh, and `StorageOAuthCredentialStore` as the host persistence hook for refreshed credentials. See [settings integration](settings-integration.md#oauth-authorization-and-token-persistence).

`ProviderDefinition::toArray()` exposes provider metadata and capabilities for UI rendering. It never contains a configured connection or secret value.

## Test and use

Test schema validation and `toFilesystemConfig()` without cloud credentials or network calls. The package's cloud provider tests use mock API responses/fakes. Optional live integration tests should be separately marked and use dedicated credentials in CI secrets. See [testing](testing.md).
