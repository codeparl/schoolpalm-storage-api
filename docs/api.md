# API reference

## `DocumentStorage`

Resolve `SchoolPalm\StorageApi\Contracts\DocumentStorage` from the container or use the `StorageApi` facade.

### Selection and invalidation

- `forContext(?string $tenantId, ?string $schoolId): static` — immutable backwards-compatible tenant/school selector.
- `forStorageContext(StorageContext $context): static` — immutable full context selector.
- `forDisk(string $disk): static` — use a disk configured in Laravel.
- `forConnection(string $connection): static` — use a named storage settings connection.
- `currentDisk(): string` — selected connection/disk name.
- `capabilities(): StorageCapabilities` — features for the resolved provider/configuration.
- `invalidate(?string $connection = null): void` — invalidate settings and matching disk adapters for this instance's context.

### Files and directories

- `exists(path): bool`, `size(path): ?int`, `mimeType(path): ?string`, `lastModified(path): ?int`.
- `files(directory = '')`, `allFiles(directory = '')`, `directories(directory = '')`, `allDirectories(directory = '')`.
- `get(path): string`, `readStream(path): mixed` (caller closes stream).
- `put(path, contents): void`, `putFile(path, SplFileInfo): void`, `writeStream(path, stream): void`, `putFromPath(path, localPath): void`, `copyToPath(path, localPath): void`.
- `delete(path): bool`, `deleteDirectory(directory): bool`, `copy(from, to): bool`, `move(from, to): bool`.

Listing and directory deletion require directory capability. Stream methods require readable/writable streams; `putFromPath()` and `copyToPath()` avoid buffering the full file in memory.

### URLs and workspace compatibility

- `publicUrl(path): ?string` and `temporaryUrl(path, ?DateTimeInterface $expiresAt = null): ?string` require provider/configuration support. Unsupported behavior raises `UnsupportedStorageOperationException`; provider errors raise sanitized `StorageApiException`.
- `physicalPath(path): ?string` returns null when unsupported.
- `batchWorkspace(batchUuid): StorageWorkspaceInterface` retains the package's existing document-workspace extension and requires a local/physical-path provider plus tenant and school context.
- `putContent(path, DocumentContent): void` streams the existing content abstraction to the active disk.

Common exceptions are `InvalidStorageConfigurationException`, `UnsupportedStorageProviderException`, `UnsupportedStorageOperationException`, and `StorageApiException`. Invalid relative paths raise `InvalidArgumentException`.

## Provider and settings contracts

- `StorageProvider`: `definition()`, `validateConfiguration()`, `toFilesystemConfig()`.
- `StorageProviderRegistry`: register trusted implementations; list schemas with `all()`, `options()`, or `toArray()`; get definitions and validate/map configurations.
- `ProviderDefinition`: stable key, label, Laravel driver, field schema, capabilities, `validate()`, `mergePreservingSecrets()`, and `toArray()`.
- `StorageContextProvider`: `current(): ?StorageContext`.
- `StorageSettingsProvider`: `settingsFor(tenantId, schoolId)`.
- `StorageSettingsRepository`: adds `defaultConnection()`, `connection(name)`, and targeted `invalidate()`.
- `ContextualStorageSettingsProvider`: resolves settings using full context and optional named connection.
- `ProviderMetadataRepository` and `StorageProviderSeeder`: host-owned idempotent metadata upserts.

## `StorageCapabilities`

Capabilities include streaming read/write, public/temporary URLs, physical paths, directory support, atomic move, server-side copy, max size, chunk size, and retry hints. Treat missing features as unsupported; do not infer a capability from the provider name alone.

The Google Drive, Dropbox, and OneDrive presets report no public or temporary URL support. Their adapters support file/folder operations and listing; pagination is handled by the provider adapter. Google Drive uses display paths while resolving provider file IDs internally. Dropbox paths use Dropbox API path semantics and can be prefixed with a configured Dropbox root. OneDrive paths are relative to the selected drive's root; a separate folder root is not configurable.

Google's client retries provider-reported rate-limit and selected transient errors. Dropbox retries upload-session request failures. OneDrive's chunked uploader retries 408, 429, and 5xx responses with bounded `Retry-After`/backoff; other Graph operations surface sanitized failures without automatic retry.

## OAuth integration

`SchoolPalm\StorageApi\OAuth\StorageOAuthService` provides provider-generic integration points:

- `authorizationUrl(provider, configuration, redirectUri, state, ?codeVerifier = null): string`
- `exchangeAuthorizationCode(provider, configuration, code, redirectUri, ?codeVerifier = null): array`
- `refresh(provider, configuration): array`

`authorizationUrl()` returns a redirect target and never persists state. When a PKCE verifier is supplied, the service sends an S256 challenge and requires the same verifier during code exchange. The host should generate and retain the verifier alongside one-time state, validate and consume both in the callback, implement the browser callback, and persist the code-exchange result securely. Token result arrays use `accessToken`, optional `refreshToken`, and `accessTokenExpiresAt` fields.

`StorageOAuthCredentialStore::save(?StorageContext, connection, provider, credentials): void` is the host-owned, encrypted persistence hook used when providers refresh credentials. Configure `storage-api.oauth_credential_store` with an implementation. The store should update the existing context-scoped connection, not create a second credential store.

Invalid, expired, or revoked OAuth credentials raise sanitized `StorageAuthorizationException` messages. The host should provide a reconnect path. These APIs do not include routes, UI, state storage, user authorization, or credential encryption.

## Built-in remote adapters

Composer installs the Google Drive, Dropbox, and OneDrive Flysystem adapters. `CloudFilesystemFactory` builds them directly; no host Laravel driver registration is required. Use the same `DocumentStorage` methods for remote file operations. S3 remains an optional host dependency (`league/flysystem-aws-s3-v3`). See [provider configuration](providers.md) for operation and URL limitations.
