# Provider configuration fields

Storage provider definitions are trusted package/application metadata, not school connections. The registry is the single source of provider IDs, field schemas, validation rules, capabilities, and authorization requirements. The field API reads those registered definitions and does not read or persist connection values.

## Public API

Use the `StorageApi` facade:

```php
use SchoolPalm\StorageApi\Facades\StorageApi;

$fields = StorageApi::providerConfigurationFields('gdrive');
$fieldObjects = StorageApi::providerFieldObjects('gdrive');
$allFields = StorageApi::allProviderConfigurationFields();
$clientSecret = StorageApi::providerConfigurationField('gdrive', 'clientSecret');
$defaults = StorageApi::providerSeedSettings();
$scopedDefaults = StorageApi::providerScopedSettings();
```

- `providerConfigurationFields($provider)` returns one provider's `ConfigurationField::toArray()` values.
- `providerFieldObjects($provider)` returns field objects for programmatic schema use. These are metadata copies with secret defaults removed.
- `allProviderConfigurationFields()` returns field arrays grouped by stable provider ID.
- `providerConfigurationField($provider, $field)` returns one field array or `null` when the field is not registered. An unknown provider raises the registry's usual unsupported-provider exception.
- `providerSeedSettings()` returns schema defaults using `provider.field` keys.
- `providerScopedSettings()` returns `secured` and `secrets` maps, each grouped by provider then field name.

Schemas contain field names, labels, types, required flags, help text, defaults, options, validation rules, and secret indicators. Provider definitions also expose their capabilities and authorization metadata:

```php
$definition = app(\SchoolPalm\StorageApi\Providers\StorageProviderRegistry::class)
    ->definition('onedrive')
    ->toArray();

$definition['key'];            // Stable ID: onedrive
$definition['fields'];         // Same safe field metadata as above
$definition['authorization'];  // OAuth flow and required scopes
$definition['capabilities'];   // Declared storage capabilities
```

The API serializes secret defaults as `null` and never includes saved connection values or OAuth tokens. Do not treat an exposed schema default as an authorized credential or connection.

## Host-owned settings structure

SchoolPalm initializes and persists settings through its existing App Settings infrastructure. The logical group remains:

```php
[
    'default_connection' => 'school-disk',
    'purpose_connections' => [
        'school-documents' => 'school-disk',
        'school-uploads' => 'school-disk',
        'school-exports' => 'school-disk',
        'school-backup' => 'google-archive',
        'school-archive' => 'school-disk',
        'school-temporary' => 'school-disk',
        'school-media' => 'school-disk',
    ],
    'providers' => [
        'gdrive' => [
            'config' => [
                'clientId' => null,
                'clientSecret' => null,
                'refreshToken' => null,
                'accessToken' => null,
                'accessTokenExpiresAt' => null,
                'folderId' => null,
                'teamDriveId' => null,
            ],
        ],
        // Each registered provider has a schema-derived null-valued template.
    ],
    'connections' => [
        'school-disk' => [
            'provider' => 'local',
            'enabled' => true,
            'config' => [],
        ],
        'school-documents' => [
            'provider' => 'gdrive',
            'enabled' => true,
            'config' => [
                'clientId' => 'host-managed-oauth-client-id',
                // Host-encrypted credentials and selected folder/drive settings.
            ],
        ],
        'google-archive' => [
            'provider' => 'gdrive',
            'enabled' => true,
            'config' => [/* Separately authorized connection. */],
        ],
    ],
]
```

The `purpose_connections` map routes each logical purpose to a named connection. New schools map all seven purposes to `school-disk`. For example, map `school-backup` to `google-archive` after that connection has been configured and authorized; uploads can continue routing to quota-managed School Disk. The bridge's purpose APIs resolve this map before calling Storage API.

The `providers` map contains UI templates generated from every registered provider definition. Each provider's `config` contains its registered field names initialized to `null`; adding a provider or field later adds only missing keys. This map is not a set of connections and is not used by the filesystem builder. The active default and configured connections live in `default_connection` and `connections`. New schools get only `school-disk` as an actual enabled connection and the initial default; other named connections are created when a school configures them.

Use `providerSeedSettings()` or `providerScopedSettings()` only as schema/default input. Initialization must be idempotent: preserve existing provider-template values, connection names, selected providers, ordinary configuration, secrets, and `default_connection`; do not replace saved school values with null schema defaults. When updating a connection, use the registered definition's `mergePreservingSecrets($submitted, $existing)` before validating the merged configuration.

The host must encrypt secrets at rest, authorize reads and writes, and omit credentials from ordinary settings API responses. The package does not own an initializer, settings table, HTTP routes, or UI.

## App Settings scope

`AppSettingsStorageSettingsProvider` reads using these scopes:

| Context | App Settings context | Group |
|---|---|---|
| Tenant and school | tenant | `storage_api.schools.{schoolId}` |
| School only | school | `storage_api` |
| Tenant only | tenant | `storage_api` |
| No context | global | `storage_api` |

The adapter's optional scope resolver can map this to a host-specific `SettingsHost` builder. The storage package does not write App Settings, so the application remains responsible for verifying that its `SettingsHost::forSchool()` writer and reader target the same underlying context/group.

The requested school/tenant context is never inferred from another school. A missing named connection returns no connection (or an explicit configuration error at use), rather than silently selecting that school's default connection. Invalidation should target both the context and connection in the storage package and clear the corresponding host settings cache.

## Provider metadata seeding

`StorageProviderSeeder` writes only `ProviderDefinition` metadata through `ProviderMetadataRepository::upsert()`. Implement that upsert idempotently using the stable provider key. It must not create or update `default_connection`, `connections`, or school credentials. No competing metadata or connection database is introduced by this package.

## OAuth boundary

Use the field schema and authorization metadata to render a provider-neutral setup form. The host owns OAuth redirects, callback routes, CSRF/state and PKCE persistence, user authorization, encryption, and initial token saving. Use `StorageOAuthService` for authorization URL/code exchange/token refresh and implement `StorageOAuthCredentialStore` against the existing encrypted settings store. Its updates must merge token changes into the existing connection config so client credentials and folder/drive selections are retained.

After a settings or token update, invalidate the matching `DocumentStorage` connection for the same `StorageContext` and clear any host settings cache for that context. Refer to [settings integration](settings-integration.md), [provider configuration](providers.md), and [security](security.md) for implementation details.
