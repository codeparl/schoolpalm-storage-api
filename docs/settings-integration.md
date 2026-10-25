# Settings integration

The core uses settings contracts and does not own persistence. A repository may read encrypted database rows, `schoolpalm/app-settings`, Laravel config, or an in-memory test map.

## Bind a host repository

For default and named connections, implement `StorageSettingsRepository`; implement `ContextualStorageSettingsProvider` as well when the full `StorageContext` is needed:

```php
use SchoolPalm\StorageApi\Contracts\ContextualStorageSettingsProvider;
use SchoolPalm\StorageApi\Contracts\StorageSettingsRepository;
use SchoolPalm\StorageApi\Settings\StorageContext;
use SchoolPalm\StorageApi\Settings\StorageSettings;

final class SchoolStorageSettings implements StorageSettingsRepository, ContextualStorageSettingsProvider
{
    public function settingsFor(?string $tenantId, ?string $schoolId): ?StorageSettings
    {
        return $this->settingsForContext(new StorageContext($tenantId: $tenantId, schoolId: $schoolId));
    }

    public function defaultConnection(?StorageContext $context = null): ?StorageSettings
    {
        // Resolve only the requested context, then an explicit application default.
        return null;
    }

    public function connection(string $name, ?StorageContext $context = null): ?StorageSettings
    {
        return null;
    }

    public function settingsForContext(?StorageContext $context, ?string $connection = null): ?StorageSettings
    {
        // Decrypt credentials using the host's secure settings system before returning.
        return $connection === null
            ? $this->defaultConnection($context)
            : $this->connection($connection, $context);
    }

    public function invalidate(?StorageContext $context = null, ?string $connection = null): void
    {
        // Invalidate only matching context/connection settings.
    }
}
```

Bind it before first resolving storage:

```php
$this->app->bind(
    \SchoolPalm\StorageApi\Contracts\StorageSettingsProvider::class,
    SchoolStorageSettings::class,
);
```

The standalone `ConfigStorageSettingsProvider` reads `storage-api.default_connection`, `storage-api.connections`, and Laravel `filesystems.default`. A configured default connection that does not exist is an error. A missing named connection is never silently replaced by a different school connection.

## App Settings adapter

`AppSettingsStorageSettingsProvider` accepts the `app-settings.manager` object at runtime; the package does not require or autoload `schoolpalm/app-settings` as a dependency.

```php
use SchoolPalm\StorageApi\Contracts\StorageSettingsProvider;
use SchoolPalm\StorageApi\Settings\AppSettingsStorageSettingsProvider;
use SchoolPalm\StorageApi\Settings\ConfigStorageSettingsProvider;

$this->app->bind(StorageSettingsProvider::class, fn ($app) =>
    new AppSettingsStorageSettingsProvider(
        $app->make('app-settings.manager'),
        new ConfigStorageSettingsProvider(),
    )
);
```

The default group is `storage_api`. With both tenant and school IDs, the adapter reads the group `storage_api.schools.{schoolId}` under the tenant context. With school-only or tenant-only context, it reads the corresponding App Settings context and the `storage_api` group. With no context, it reads the global group. Pass a custom scope resolver to match an existing App Settings schema:

```php
new AppSettingsStorageSettingsProvider(
    $manager,
    $fallback,
    'storage_api',
    fn ($manager, $context, $group) => $manager
        ->context('school', $context->schoolId)
        ->group($group),
);
```

Expected settings group:

```php
[
    'default_connection' => 'school-files',
    'connections' => [
        'school-files' => [
            'provider' => 's3',
            'config' => [
                'key' => 'encrypted-by-host',
                'secret' => 'encrypted-by-host',
                'region' => 'us-east-1',
                'bucket' => 'school-files',
            ],
        ],
    ],
]
```

Use the host's encryption facilities for credential fields and protect write access through host authorization. This adapter reads/decrypts only if the backing App Settings integration itself returns decrypted values; the storage package does not encrypt credentials.

## OAuth authorization and token persistence

Google Drive, Dropbox, and OneDrive use the package-installed adapters and `StorageOAuthService`. The package intentionally does not register routes or provide UI. Use the registry metadata to render provider-specific setup:

```php
$definition = app(\SchoolPalm\StorageApi\Providers\StorageProviderRegistry::class)
    ->definition($providerId)
    ->toArray();
// $definition['fields'], ['authorization']['scopes'], and ['capabilities']
```

The host creates a cryptographically random, one-time `state` and PKCE verifier, binds both to the initiating user and school in a server-side session/cache, then:

```php
$oauth = app(\SchoolPalm\StorageApi\OAuth\StorageOAuthService::class);
$verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$url = $oauth->authorizationUrl($providerId, $connectionConfig, $callbackUrl, $state, $verifier);
// Redirect the user's browser to $url.
```

In the callback, the host validates and consumes both values before exchanging the code:

```php
$tokens = $oauth->exchangeAuthorizationCode(
    $providerId,
    $connectionConfig,
    $request->string('code')->toString(),
    $callbackUrl,
    $verifier,
);
$credentialStore->save($storageContext, $connectionName, $providerId, $tokens);
app(\SchoolPalm\StorageApi\Contracts\DocumentStorage::class)->forStorageContext($storageContext)
    ->invalidate($connectionName);
```

`StorageOAuthCredentialStore` is the persistence hook. Implement it using the host's existing, encrypted settings repository; do not add a parallel token database. `save()` receives only changed token fields, so merge them into the connection's existing configuration rather than replacing its client credentials or folder/drive selection. Invalidate the host settings cache for the same context and connection after saving. Configure its class:

```php
'oauth_credential_store' => \App\Storage\SchoolStorageOAuthCredentialStore::class,
```

The store receives the full context, connection name, and stable provider ID so it can update only that school's existing connection. Refreshes occur when access tokens expire (OneDrive per Graph request; Dropbox after an auth failure; Google via the Google client refresh support). Refreshed access/refresh tokens and expiry are saved through the store. A host that does not configure this hook can use already-valid access tokens but receives an explicit authorization error when refresh requires persistence.

The OAuth library does not validate callback state; CSRF/state creation, validation, callback authorization, redirect URI registration, encryption, token response handling, and user-facing reconnection notices are host responsibilities. Never send token responses to a browser or log them. Provider revocation/expired refresh tokens produce a sanitized `StorageAuthorizationException`; the host should ask the school to reconnect.

## Provider metadata and seeding

Provider definitions are metadata, not school connections. Expose them with `StorageProviderRegistry::toArray()`. To seed them, implement `ProviderMetadataRepository::upsert()` keyed by the provider's stable ID and invoke `StorageProviderSeeder::seed()`. Implement the upsert idempotently and never overwrite school-owned connections or credentials. The package intentionally does not create a competing metadata table or modify saved settings.

After changing settings, call `DocumentStorage::invalidate()` for the affected active context/connection. A custom repository should also clear its matching settings cache.
