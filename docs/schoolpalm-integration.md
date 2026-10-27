# Integrating with SchoolPalm and other packages

This package is an infrastructure component, not a replacement for a SchoolPalm application's settings, tenant resolution, or access-control systems. It gives those systems a stable storage API and a small number of integration contracts.

## Responsibility boundary

```text
SchoolPalm application / host package
  ├─ identifies the current user, tenant, school, and organization
  ├─ authorizes access to the file and the selected storage connection
  ├─ stores connection settings and encrypts secrets
  ├─ owns settings UI, OAuth routes, callback state, and consent UX
  └─ selects a purpose-specific connection
                 │
                 │ StorageContext + StorageSettingsProvider
                 │ DocumentStorage + OAuth service/store contracts
                 ▼
schoolpalm/storage-api
  ├─ validates provider metadata and relative paths
  ├─ resolves a connection for the supplied context
  ├─ scopes paths and caches adapters per context/connection
  ├─ exposes file operations and declared capabilities
  └─ builds its built-in Google Drive, Dropbox, and OneDrive adapters
                 │
                 ▼
Laravel filesystem / Flysystem / cloud provider APIs
```

The storage package does not decide whether a teacher, administrator, student, or background process may access a document. It also does not choose whether a school should use a connection named `documents`, `exports`, or `archive`; that is application policy. Keeping those decisions in SchoolPalm allows storage to follow the application's established authorization rules rather than creating a second policy system.

## Package ecosystem integration

### Laravel

Composer's Laravel package discovery registers `StorageApiServiceProvider`. The provider binds `DocumentStorage`, `StorageProviderRegistry`, `StorageOAuthService`, and the cloud filesystem factory. Use dependency injection in services and jobs; use the facade for concise application call sites. To use an existing Laravel disk directly, call `forDisk('disk-name')`.

### Flysystem and providers

The public API is provider-neutral and delegates to Laravel/Flysystem adapters. The package installs Google Drive, Dropbox, and OneDrive adapter dependencies as normal Composer requirements and builds those adapters directly. Hosts do not need to add Laravel driver registrations for these three providers. S3's Flysystem AWS adapter remains optional and is installed by applications that use S3.

Provider definitions in the registry describe the configuration form, authorization requirements, and capabilities. They do not constitute an authorized connection. A school connection is saved separately by the host settings system.

### SchoolPalm App Settings

`AppSettingsStorageSettingsProvider` is an optional bridge to the runtime `app-settings.manager` binding. The package does not require `schoolpalm/app-settings` or its database schema. The host can instead implement `StorageSettingsProvider` or `StorageSettingsRepository` against any existing settings repository.

The adapter reads actual named connections from the `connections` map. SchoolPalm may also store a `providers` map containing null-valued configuration templates for its settings UI. These templates are generated from the registered provider definitions, are not connections, and are not used to build filesystems. New schools can keep `school-disk` as their default while an administrator later creates a named connection that selects a configured provider:

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
        // Other registered providers are generated the same way.
    ],
    'connections' => [
        'school-disk' => [
            'provider' => 'local',
            'enabled' => true,
            'config' => [],
        ],
        'google-archive' => [
            'provider' => 'gdrive',
            'enabled' => true,
            'config' => [/* Separate encrypted connection configuration. */],
        ],
    ],
]
```

The `purpose_connections` map selects which named connection each bridge API uses. For example, setting `school-backup` to `google-archive` routes `putBackup()` to that Google Drive connection, while `school-uploads` can remain on `school-disk` and retain SchoolPalm quota enforcement. Each purpose defaults to `school-disk`. A remote destination must first exist as an enabled named entry in `connections`.

The `providers` map is initialized from `StorageApi::allProviderConfigurationFields()` with missing field values set to `null`. Existing values are preserved. A real provider connection stores its selected provider and configuration under `connections.{connectionName}`; only that connection is consumed by the storage API. Null templates are never treated as authorization or configuration.

The example illustrates the configuration shape; it is not an instruction to store plaintext secrets. The host must encrypt/decrypt secrets using its existing secure settings facility. See [settings integration](settings-integration.md) for adapter binding, context lookup, and metadata seeding.

### SchoolPalm tenant and school context

Map the application's trusted active context to `StorageContext`. The exact service that supplies tenant and school IDs is application-specific; adapt your existing tenant resolver rather than making this package query application models:

```php
use SchoolPalm\StorageApi\Contracts\StorageContextProvider;
use SchoolPalm\StorageApi\Settings\StorageContext;

final class SchoolPalmStorageContextProvider implements StorageContextProvider
{
    public function __construct(private readonly CurrentSchoolContext $current) {}

    public function current(): ?StorageContext
    {
        $scope = $this->current->get();
        if ($scope === null) {
            return null;
        }

        return new StorageContext(
            applicationId: $scope->applicationId(),
            tenantId: $scope->tenantId(),
            schoolId: $scope->schoolId(),
            organizationId: $scope->organizationId(),
        );
    }
}
```

Bind this adapter in the host application's service provider. Replace `CurrentSchoolContext` and its accessor methods with the host's actual context API. Do not derive context from untrusted request parameters without checking the authenticated user's membership and permissions.

For a queue worker, scheduled task, CLI operation, or other context where no request context exists, pass a context explicitly:

```php
$storage = app(\SchoolPalm\StorageApi\Contracts\DocumentStorage::class)
    ->forStorageContext(new StorageContext(
        tenantId: $job->tenantId,
        schoolId: $job->schoolId,
    ))
    ->forConnection('school-documents');
```

The job's IDs should come from a trusted, serialized domain operation. Contextual settings lookup must use the full scope; never fall back from a missing school connection to another school's saved credentials.

### Document generation and other SchoolPalm packages

A package that generates documents can depend on `DocumentStorage` to persist outputs without knowing whether a school uses local storage, S3, Google Drive, Dropbox, or OneDrive. A content producer can pass a `DocumentContent` value to `putContent()` when it already uses that package contract.

The package retains a specialized `batchWorkspace()` extension for workflows needing manifest/source/plan/status files, chunks, rendered files, and a physical filesystem path. This feature is not a general remote-cloud workspace abstraction: it requires tenant and school context and a provider that exposes a physical path (normally private local storage). Keep cloud-ready file interchange on `DocumentStorage` streams instead.

Other packages should depend on the narrow contracts they need and should not depend on `StorageOAuthService` unless they implement the application's connection-setup workflow. The SchoolPalm application owns that workflow.

## Connect a school to a cloud provider

### 1. Install and configure provider metadata

```sh
composer require schoolpalm/storage-api
php artisan vendor:publish --tag=storage-api-config
```

The Google Drive, Dropbox, and OneDrive adapters and their SDK dependencies install with Composer. Create the provider's OAuth application using the callback URI hosted by SchoolPalm. Make the corresponding client ID and secret available to the host's secure connection-management workflow. Do not put per-school access or refresh tokens in `.env` or source-controlled config.

The host may render the provider form and consent action from the registry instead of hardcoding a provider-specific form:

```php
$definitions = app(\SchoolPalm\StorageApi\Providers\StorageProviderRegistry::class)->toArray();
$definition = collect($definitions)->firstWhere('key', $providerId);

$fields = $definition['fields'];
$authorization = $definition['authorization'];
$capabilities = $definition['capabilities'];
```

The host should validate a submitted connection using the registry, merge omitted secret values from the existing configuration when updating, and save the connection only after applying its own authorization rules. Provider field schemas intentionally do not contain credential values.

### 2. Begin OAuth with state and PKCE

Create a cryptographically random, one-time state and PKCE verifier and store them server-side, bound to the initiating user, tenant, school, provider, and connection being configured. Use the provider's registered callback URI:

```php
$state = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

$authorizationUrl = app(\SchoolPalm\StorageApi\OAuth\StorageOAuthService::class)
    ->authorizationUrl(
        provider: $providerId,
        configuration: $oauthClientConfiguration,
        redirectUri: $callbackUrl,
        state: $state,
        codeVerifier: $verifier,
    );
```

Redirect the user's browser to the returned URL. The package builds provider authorization URLs and scopes. It deliberately does not create routes, sessions, callback handlers, or UI.

### 3. Validate callback and persist the connection

In the callback, first authenticate/authorize the callback operation according to the host application, verify the state matches the server-side record, consume it exactly once, and retrieve the matching PKCE verifier and intended school/connection. Do not accept context identifiers from the callback as authority.

Exchange the authorization code and merge the normalized credentials into the intended connection:

```php
$tokens = app(\SchoolPalm\StorageApi\OAuth\StorageOAuthService::class)
    ->exchangeAuthorizationCode(
        provider: $providerId,
        configuration: $oauthClientConfiguration,
        code: $request->string('code')->toString(),
        redirectUri: $callbackUrl,
        codeVerifier: $storedVerifier,
    );

// Application-specific: merge $tokens into the selected school's existing
// connection config and save it through the host's encrypted settings system.
// Preserve client credentials and provider options such as folderId/driveId.
```

`StorageOAuthCredentialStore` is the package's hook for persisting refreshed tokens during non-interactive storage operations. Implement it using the host's existing encrypted settings repository and configure `storage-api.oauth_credential_store` with that implementation. Its `save()` receives the context, connection name, provider ID, and changed token fields; merge those fields into the existing connection rather than replacing other configuration. Invalidate the host settings cache for that exact connection/context after persisting.

The initial callback exchange is returned to the host; the host must save it securely itself. The credential-store hook does not own or replace the settings repository.

### 4. Use the selected school connection

```php
$schoolStorage = app(\SchoolPalm\StorageApi\Contracts\DocumentStorage::class)
    ->forStorageContext($schoolContext)
    ->forConnection('school-documents');

$schoolStorage->putFromPath(
    'student-records/'.$recordId.'/enrollment.pdf',
    $validatedLocalUploadPath,
);
```

Use a path generated from authorized domain identifiers and validated names. The package scopes ordinary paths to context; it does not authorize the user's access to a record.

## Provider selection and purpose policy

One school may have multiple named connections. The Module Bridge's `StorageConnectionResolver` reads `purpose_connections` from the school settings and routes purpose APIs accordingly. For example, it can route backups to Google Drive while uploads remain on quota-managed School Disk:

```php
StorageHost::putBackup('daily/archive.zip', $backupBytes); // google-archive
StorageHost::putUpload('student/photo.jpg', $uploadBytes); // school-disk
```

The host still owns the settings UI and authorization policy for changing these assignments. The package does not choose providers from file contents or infer a purpose.

## Provider metadata seeding

If SchoolPalm keeps provider definitions in a UI/catalog table, seed from `StorageProviderRegistry` using the host's `ProviderMetadataRepository` implementation and `StorageProviderSeeder`. Upsert by stable provider ID so repeated seeding is idempotent. Provider metadata seeding must be kept separate from school connection settings; it must never overwrite credentials, authorized account state, or a school's selected folder/drive.

## Reauthorization and errors

Expired access tokens are refreshed by the provider integration where supported. If a refresh token has expired or been revoked, OAuth fails with a sanitized authorization exception. The host should mark the connection as requiring reconnection and offer a new consent flow to an authorized administrator. Do not expose tokens, provider response bodies, or raw callback parameters in API responses or logs.

## Integration checklist

- [ ] Bind the application's trusted context adapter, or pass `StorageContext` explicitly in every background operation.
- [ ] Bind a context-aware settings provider that reads the exact school's saved connection.
- [ ] Encrypt OAuth client secrets, access tokens, and refresh tokens in the host's existing settings store.
- [ ] Configure a `StorageOAuthCredentialStore` that merges refreshed tokens into the same school connection.
- [ ] Implement state/PKCE storage, validation, callback routes, consent UI, and host authorization.
- [ ] Use provider metadata and capability declarations to render setup and choose valid operations.
- [ ] Invalidate the affected storage connection/settings cache when configuration changes.
- [ ] Keep queue payloads limited to trusted identifiers and resolve credentials at execution time.
- [ ] Exercise cloud operations with fakes in the normal test suite; keep any live tests separate.

Related guides: [settings integration](settings-integration.md), [context integration](context-integration.md), [security](security.md), [provider configuration](providers.md), and [usage](usage.md).
