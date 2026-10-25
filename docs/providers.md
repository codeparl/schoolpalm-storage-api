# Provider configuration

The provider registry describes and validates connections; `CloudFilesystemFactory` constructs the package-installed Flysystem adapters. The host application owns connection persistence and OAuth routes. Credentials must be loaded from the active tenant/school settings context and encrypted by the host.

```php
'connections' => [
    'school-drive' => [
        'provider' => 'gdrive',
        'config' => [
            'clientId' => 'configured-by-host',
            'clientSecret' => 'encrypted-by-host',
            'refreshToken' => 'encrypted-by-host',
            'folderId' => 'authorized-folder-id',
        ],
    ],
],
```

The client ID and secret are the OAuth application's credentials, not a school's access token. The account grants consent through the host application. See [OAuth setup](settings-integration.md#oauth-authorization-and-token-persistence).

## Local

```php
'provider' => 'local',
'config' => ['root' => storage_path('app/private'), 'visibility' => 'private'],
```

Without a configured `storage-api` connection, the package uses `filesystems.default`.

## Amazon S3

S3 remains an optional host dependency:

```sh
composer require league/flysystem-aws-s3-v3
```

```php
'provider' => 's3',
'config' => [
    'key' => env('STORAGE_ACCESS_KEY'),
    'secret' => env('STORAGE_SECRET_KEY'),
    'region' => env('STORAGE_REGION', 'us-east-1'),
    'bucket' => env('STORAGE_BUCKET'),
    'visibility' => 'private',
],
```

The access key and secret may be omitted when the AWS SDK's credential provider chain is used. If one is set, both must be set. `bucket` is required. Private S3 connections do not return an unsigned public URL; use a temporary signed URL when authorized.

## Google Drive

`masbug/flysystem-google-drive-ext` and Google's API client are installed automatically by Composer. Configure an OAuth web application with the host callback URL and request the `https://www.googleapis.com/auth/drive.file` scope. Google requires offline consent to issue a refresh token; the host should preserve it securely. The package exposes the authorization URL, code exchange, and refresh calls through `StorageOAuthService`.

Fields in the registry schema:

- `clientId`, `clientSecret`: OAuth app credentials.
- `refreshToken`, `accessToken`, `accessTokenExpiresAt`: host-persisted account credentials. Tokens are secret in schema/serialization.
- `folderId`: optional authorized folder ID. If omitted, the adapter uses/creates a `SchoolPalm Storage` folder under the authorized Drive.
- `teamDriveId`: optional Google Shared Drive ID.

Use Google Picker or another host UI to let an authorized user select a folder/shared drive, then save its ID in that school's existing storage connection. Do not allow a client to submit an arbitrary folder ID without verifying the selected user's access.

Supported: read/write and stream operations, existence and metadata, listing, folder operations, delete, copy, and move. Drive paths are display paths resolved by the adapter; the API's file IDs remain internal. Google Drive public links are not enabled by this integration; both URL capabilities are false.

## Dropbox

`spatie/flysystem-dropbox` and the Dropbox API client are installed automatically. Register an OAuth app in Dropbox, configure the host callback URL and the scopes returned by the `dropbox` provider schema, and request offline access. Configure `clientId`, `clientSecret`, and optionally `root` for the path prefix in Dropbox. Dropbox App Folder permission is suitable for an isolated app folder; Full Dropbox access should only be used when the school explicitly needs it.

The host stores `refreshToken`, `accessToken`, and `accessTokenExpiresAt` securely. Access tokens are refreshed by the package's token provider on an authentication failure; upload-session retries are enabled. The optional `root` is applied as a Dropbox path prefix. Dropbox folder paths are API paths, not local filesystem directories. The adapter follows Dropbox list cursors for pagination.

Supported: read/write and streaming (including Dropbox upload sessions), existence/metadata, paginated listing, directory operations, delete, copy, and move. The adapter does not create public/shared links; URL capabilities are false.

## Microsoft OneDrive

`justus/flysystem-onedrive` and Microsoft Graph SDK are installed automatically. Configure a confidential Microsoft Entra ID OAuth application for the host callback URI and delegated permissions `offline_access`, `Files.ReadWrite`, and `User.Read`. The default tenant authority is `common`; set `tenantId` to a specific Entra tenant where desired.

Fields include `clientId`, `clientSecret`, host-persisted refresh/access tokens, optional `tenantId`, and optional `driveId`. If `driveId` is omitted, the package resolves the authorized account's default drive through `/me/drive`. Set a drive ID for a specific drive; the host can render this field from its account/drive selection UI. Paths are relative to the selected drive's root; a separate folder root is not currently configurable.

Supported: read/download, write and chunked upload, existence/metadata, paginated listing, directory operations, delete, copy, and move. URL capabilities are false because the adapter does not issue links that grant access. Chunk uploads retry HTTP 408, 429, and 5xx responses up to ten attempts, honoring `Retry-After` (bounded to 60 seconds) or using capped exponential backoff. Other Graph operations surface sanitized errors and do not have package-level automatic retries.

The selected Flysystem adapter is `justus/flysystem-onedrive` v2.0.8 (MIT). It currently requires Microsoft's v1 Graph PHP SDK (`microsoft/microsoft-graph` ^1.110); that SDK's v1.110.0 release is older than Microsoft's current major SDK line, which is not API-compatible with this adapter. The integration uses the stable Microsoft Graph REST API, but upgrading to the current SDK line requires a compatible Flysystem adapter or a package-owned adapter migration.

## S3-compatible service

Use the `s3` provider and set an endpoint. This requires the optional AWS adapter above:

```php
'provider' => 's3',
'config' => [
    'key' => env('OBJECT_ACCESS_KEY'),
    'secret' => env('OBJECT_SECRET_KEY'),
    'region' => env('OBJECT_REGION', 'auto'),
    'bucket' => env('OBJECT_BUCKET'),
    'endpoint' => env('OBJECT_ENDPOINT'),
    'use_path_style_endpoint' => true,
    'visibility' => 'private',
],
```

## OAuth callback and connection selection

Use the `authorization` information in `StorageProviderRegistry::toArray()` to render generic provider setup. Generate and persist one-time CSRF `state` and a PKCE verifier in the host session/cache, redirect to `StorageOAuthService::authorizationUrl()` with both values, validate and consume them in the host callback, exchange the code with `exchangeAuthorizationCode()` and the verifier, and save the returned credentials into the existing context-scoped connection. Never log or return the token response to an ordinary settings API.

Select the saved school connection using `DocumentStorage::forConnection('school-drive')` after resolving the correct `StorageContext`. When refreshed credentials are saved, call `DocumentStorage::invalidate('school-drive')`.

OAuth URLs/callbacks are integration points, not routes or UI. The package does not own application HTTP endpoints, CSRF storage, credential encryption, or user authorization.
