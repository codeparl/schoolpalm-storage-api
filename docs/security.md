# Security

## Credentials

- Store credentials in the host application's encrypted settings system or secure secret store.
- Do not store real credentials in source-controlled config, seeds, documentation, tests, or logs.
- Provider schemas expose secret flags and never return configured secret values.
- OAuth access tokens, refresh tokens, and client secrets are secret fields. Token responses are returned only to the host callback/service code and must be saved by the host's encrypted settings repository.
- Use `StorageOAuthCredentialStore` to persist refreshes for the exact context and connection. If refresh persistence is required but no store is configured, the provider fails explicitly instead of silently losing rotated credentials.
- Generate cryptographically random, one-time OAuth `state` and PKCE verifier values, bind them to the initiating user and school, validate and consume both in the callback, and use registered HTTPS redirect URIs. The service supports S256 PKCE; `StorageOAuthService` deliberately does not own HTTP routes, sessions, or CSRF validation.
- Request only the scopes shown by provider metadata. The built-in integrations do not create public/shared links; their public and temporary URL capabilities are false.
- Use `ProviderDefinition::mergePreservingSecrets()` for updates that omit a secret field; validate the merged configuration before saving.
- Custom settings repositories are responsible for encryption/decryption and access authorization.

## Context isolation and paths

Resolve settings by the complete context and connection identity. Never fall back to another tenant's configuration. The only safe fallback is a deliberate application-level default. Cache identity includes context and connection, and cached configuration is replaced when its signature changes.

Paths are storage-relative. Absolute paths, traversal segments, and invalid workspace paths are rejected. Tenant/school prefixes reduce accidental collisions but do not replace access control.

## URLs and visibility

Private S3 configurations do not expose unsigned public URLs. `temporaryUrl()` creates a signed URL only where the provider supports it. Signed URLs are bearer credentials: authorize the user before issuing one, keep expirations short, and do not log or persist the URL unnecessarily.

The Google Drive integration requests the limited `drive.file` scope and scopes operations to an authorized folder or Shared Drive where configured. Dropbox uses the registered content and metadata scopes; prefer App Folder permission unless the school requires broader access. OneDrive requests delegated `offline_access`, `Files.ReadWrite`, and `User.Read`. Google web-view URLs and Microsoft download URLs are not treated as public or temporary URLs by this package.

Local public URLs are available only when the configured disk declares public visibility; temporary URLs require local serving to be enabled. Public URLs do not make private files accessible.

## Provider trust boundary

Provider classes are installed Laravel code and are registered through trusted service-provider/configuration mechanisms. Connection data cannot select PHP classes, execute code, or dynamically load provider code.

## Errors and logs

Storage operation and OAuth exceptions use sanitized messages and do not include configuration, credentials, file contents, provider response bodies, or previous exception messages. The package does not log storage credentials or URLs. Host applications should apply the same redaction policy to their settings and storage logs, and avoid logging OAuth callback query strings.
