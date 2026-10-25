Architecture concept · SchoolPalm

# SchoolPalm Storage Architecture

A unified, context-aware storage layer for module authors and schools—combining SchoolPalm-managed quota storage with school-selected external providers, clear ownership, and lifecycle policies.

ModuleBridge adapter Named connections School Disk quota External file systems Retention policies

**On this page** [Goals and principles](#goals) [Architecture](#architecture) [Standard connections](#connections) [Providers and configuration](#providers) [Module-author API](#module-api) [School Disk and quota](#school-disk) [Retention and lifecycle](#lifecycle) [Unified file manager](#file-manager) [Security and isolation](#security) [Implementation phases](#implementation) [Decisions to finalize](#decisions)

## 1. Goals and principles

SchoolPalm should let modules store files without coupling their code to Google Drive, S3, OneDrive, Dropbox, or any other storage provider.

- **Stable module contract:** module authors use SchoolPalm's storage adapter rather than provider SDKs.
- **School-owned configuration:** each school chooses and authorizes providers for the standard connections it wants to use.
- **Default storage:** until a school explicitly changes its default, ordinary storage operations use its quota-managed `school-disk`.
- **Context isolation:** connection settings and credentials are resolved for the active tenant and school; no other school's credentials may be used as fallback.
- **Purpose-aware storage:** documents, uploads, exports, backups, archives, and temporary files can have distinct destinations and retention rules.
- **Lifecycle management:** data may persist for years, until an academic-cycle boundary, or for a short operational period according to policy.

**Core rule:** a connection name identifies a logical destination in SchoolPalm. The school chooses the provider and actual location behind that name. Modules depend on the logical contract, not the provider.

## 2. Architecture and responsibilities

**School module**  
Requests a storage operation

→

**ModuleBridge StorageHost**  
Proposed facade &amp; policy boundary

→

**DocumentStorage**  
provider-neutral storage engine

schoolpalm/storage-api

### Provider-neutral storage engine

Exposes `DocumentStorage` via container or `StorageApi` facade, handling provider integrations, connections, file operations, and capability reporting.

ModuleBridge

### Proposed StorageHost facade

Provides concise, policy-aware operations (`StorageHost::put()` and `StorageHost::putDocument()`) translating module intent to storage engine calls.

SchoolPalm core

### School-level policies

Owns context, actor/module authorization, connection settings, plan entitlements, School Disk quota accounting, retention policy, and audit events.

SchoolPalm UI

### Administration and file browsing

Lets authorized users connect providers, choose destinations, browse accessible files, inspect quota usage, and manage retention or cleanup workflows.

**Separation of responsibility:** the underlying storage engine does not independently decide whether a teacher can access a file, whether an upload counts against a SchoolPalm plan, or when official school records should be deleted. Those are SchoolPalm policies enforced by the adapter and core services.

## 3. Standard SchoolPalm connections

SchoolPalm defines stable logical connection names. Each school configures the provider and destination for each connection it enables. The names below are a proposed standard set; the exact list can evolve without changing the underlying storage engine.

| Connection         | Purpose                                                                                                                       | Default / quota behavior                                                                        | Typical lifecycle                                                  |
|--------------------|-------------------------------------------------------------------------------------------------------------------------------|-------------------------------------------------------------------------------------------------|--------------------------------------------------------------------|
| `school-disk`      | SchoolPalm-managed file area for ordinary storage when no other default is selected. Target of `StorageHost::put()`.          | **Initial default.** Counts toward the school's plan quota.                                     | Depends on the file's retention policy.                            |
| `school-documents` | Generated documents, reports, PDFs, letters, and other durable school files. Target of `StorageHost::putDocument()`.          | Uses the school's configured default connection; falls back to `school-disk` if policy permits. | Often long-lived; official records may require extended retention. |
| `school-uploads`   | Files uploaded by authorized school users or accepted through module workflows, such as attachments and supporting documents. | Configured provider; otherwise use the defined default policy.                                  | Varies by upload category and record association.                  |
| `school-exports`   | Generated CSV, XLSX, PDF, ZIP, and other export artifacts intended for download or transfer.                                  | Configured provider; do not assume all exports count toward School Disk.                        | Often temporary or academic-cycle scoped.                          |
| `school-backup`    | Destination for school-managed exports/backups coordinated by SchoolPalm, including selected-module exports.                  | Configured provider; backup completion must be verified and reported accurately.                | Version-count or time-based retention.                             |
| `school-archive`   | Longer-term storage for records and artifacts that should be retained beyond ordinary operational periods.                    | Configured provider, potentially lower-cost storage.                                            | Long-term, policy-governed retention.                              |
| `school-temporary` | Temporary processing files, intermediate output, and short-lived artifacts.                                                   | Use a suitable configured connection or a controlled temporary area.                            | Short expiry; clean up after successful processing where possible. |
| `school-media`     | Images, audio, video, and other media used by school websites or modules.                                                     | Configured provider; large files may warrant a separate destination.                            | Based on ownership and content purpose.                            |

**Recommended additional distinction:** connection names describe purpose and routing, not permission. A user still needs authorization to access the connection and the requested file.

### Fallback rules

- `school-disk` is the initial default for general-purpose storage.
- Connecting an external provider does not automatically change the default.
- Purpose-specific connections can be configured independently.
- If a purpose-specific connection (such as the default connection for `putDocument()`) is absent, fallback to `school-disk` is conditional on the applicable policy.
- Do not silently treat a failed external backup as a successful operation or redirect it to an unexpected destination.

## 4. Providers, connections, and school settings

A **provider** is a storage technology or integration, such as local storage, Amazon S3, Google Drive, Dropbox, or OneDrive. A **connection** is a named SchoolPalm destination configured to use one provider and one authorized account, bucket, folder, or root.

| Concept                    | Example                                                | Owner                                           |
|----------------------------|--------------------------------------------------------|-------------------------------------------------|
| Provider integration       | `gdrive`, `s3`, `dropbox`, `onedrive`, `local`         | `schoolpalm/storage-api` package                |
| Standard connection name   | `school-documents`                                     | SchoolPalm contract                             |
| Provider choice and target | Google Drive folder selected by a school administrator | School administrator / SchoolPalm settings      |
| Secret storage             | OAuth refresh token or access credentials              | SchoolPalm settings/credential store, encrypted |
| Default connection         | `school-disk` until the school selects another default | SchoolPalm configuration                        |

### Example school configuration (conceptual)

```json
{
  "default_connection": "school-disk",
  "connections": {
    "school-disk": {
      "provider": "school-disk",
      "enabled": true
    },
    "school-documents": {
      "provider": "gdrive",
      "enabled": true,
      "target": "authorized-folder-reference"
    },
    "school-backup": {
      "provider": "s3",
      "enabled": true,
      "target": "authorized-bucket-reference"
    }
  }
}
```

Illustrative only. Actual connection settings should follow Storage API's schema and SchoolPalm's secure credential storage. The special `school-disk` connection may be implemented as a SchoolPalm-managed storage backend rather than an external cloud provider.

Never put real tokens, passwords, or client secrets in module manifests, source control, or plain-text configuration exports.

## 5. Module-author API

Module authors should use SchoolPalm's proposed `StorageHost` facade rather than importing provider SDKs, reading connection settings directly, or instantiating lower-level storage classes. These examples describe the intended API contract; names and signatures must be aligned with the actual implementation before being treated as executable package documentation.

### Proposed Module-Author Facade

```php
use SchoolPalm\ModuleBridge\Facades\StorageHost;

// Always writes to quota-managed School Disk.
StorageHost::put('module-folder/example.json', $contents);

// Resolves the school's configured default connection.
StorageHost::putDocument('reports/term-one.pdf', $pdfContents);
```

### Underlying DocumentStorage Contract (Package Level)

The underlying `schoolpalm/storage-api` package exposes `SchoolPalm\StorageApi\Contracts\DocumentStorage` (resolvable from the container or via the `StorageApi` facade), supporting immutable context, disk/connection selectors, and robust file operations:

```php
use SchoolPalm\StorageApi\Facades\StorageApi;

// Lower-level package usage with context and connection selection
$storage = StorageApi::forContext($tenantId, $schoolId)
    ->forConnection('school-documents');

$exists = $storage->exists('reports/summary.pdf');
$size = $storage->size('reports/summary.pdf');
$stream = $storage->readStream('reports/summary.pdf');
```

### Actual Storage API capabilities

- **Immutable context &amp; selection:** `forContext(?string $tenantId, ?string $schoolId)`, `forStorageContext(StorageContext $context)`, `forDisk(string $disk)`, and `forConnection(string $connection)` return configured instances without mutating global state.
- **Inspection:** `currentDisk()` returns the active disk or connection name; `capabilities()` returns provider/configuration capabilities; `invalidate(?string $connection = null)` clears cached instances.
- **File operations:** `exists()`, `size()`, `mimeType()`, `lastModified()`, `files()`, `allFiles()`, `directories()`, `allDirectories()`, `get()`, `readStream()`, `put()`, `putFile()`, `writeStream()`, `putFromPath()`, `copyToPath()`, `delete()`, `deleteDirectory()`, `copy()`, and `move()`.
- **URLs &amp; Workspaces:** `publicUrl()`, `temporaryUrl()` (may return `null` when unsupported), `physicalPath()` (may return `null`), `batchWorkspace()` (requires local/physical-path provider plus tenant and school context), and `putContent()` (streams existing `DocumentContent` abstractions).
- **Exceptions:** Invalid relative paths raise `InvalidArgumentException`. Missing capabilities must be treated as unsupported. Unsupported operations raise `UnsupportedStorageOperationException`, provider failures raise sanitized `StorageApiException`, and invalid/expired/revoked OAuth credentials raise `StorageAuthorizationException` alongside configuration exceptions (`InvalidStorageConfigurationException`, `UnsupportedStorageProviderException`).

## 6. School Disk and subscription quota

**School Disk is a quota-managed SchoolPalm storage resource.** A plan might include 5 GB per school, while another plan may provide a different quota. The actual limit comes from the school's plan and current entitlements.

### Routing &amp; Quota Rules

- `StorageHost::put()` always targets `school-disk`, enforcing the school's quota and routing through the central quota-aware service.
- `StorageHost::putDocument()` resolves the configured default connection. If no default is configured and fallback is explicitly permitted by policy, it delegates internally to the equivalent of `StorageHost::put()` (writing to School Disk and enforcing its quota).
- Connecting Google Drive, Dropbox, OneDrive, or S3 does not automatically redirect generic `put()` operations.
- Files stored through School Disk count toward the school's plan quota. External-provider storage does not count toward School Disk quota by default; external billing and usage remain separate.
- Quota checks account for replacement writes as size differences, concurrency, failed uploads, deletions, and moves.
- A quota failure must not trigger an implicit external-provider spillover.

**Quota invariant:** all writes to School Disk must pass through the quota-aware service. Direct writes around that service would make usage reporting and plan enforcement unreliable.

## 7. Retention, academic cycles, and cleanup

Schools generate files at different rates, and those files do not all have the same useful lifetime. Storage should carry lifecycle metadata, and SchoolPalm should run policy-driven cleanup rather than embedding deletion rules in every module.

| Policy category             | Example                                                  | Expected handling                                                                                 |
|-----------------------------|----------------------------------------------------------|---------------------------------------------------------------------------------------------------|
| Permanent / official record | Final academic results or approved institutional records | Retain according to applicable records policy; no automatic deletion merely because a cycle ends. |
| Academic-cycle              | Temporary class lists or term-specific generated exports | Eligible for review or expiry after the cycle, possibly with a grace period.                      |
| Time-limited                | One-time report download                                 | Expire after a configured duration.                                                               |
| Temporary processing        | Intermediate file produced while generating a report     | Delete after successful processing or after a short safety window.                                |
| Versioned backup            | Module backup archive                                    | Keep a configured number of versions or retain for a specified period.                            |
| Archive                     | Historical school documents                              | Move or retain in an archive connection subject to authorization and policy.                      |

### Lifecycle metadata (conceptual)

```php
[
    'retention_policy' => 'academic-cycle',
    'academic_year_id' => $academicYearId,
    'expires_at' => null,
    'owner_module' => 'schoolpalm.common.academiccalendar',
]
```

Use either a policy identifier or an explicit expiry date as appropriate; do not allow an arbitrary module-supplied expiry to override mandatory SchoolPalm or legal retention rules. Keep platform-managed full-tenant database disaster-recovery backups separate from school-managed exports and module-produced backup artifacts.

## 8. Unified SchoolPalm file manager

SchoolPalm can present a unified file-management UI for the school's authorized storage locations, while keeping files in their configured providers instead of copying everything into SchoolPalm.

### School Disk

Browse school-managed files and view quota used, quota limit, and remaining capacity.

### External connections

Browse authorized folders, list supported metadata, and upload or download files where the provider and permissions allow it.

### Module files

Show ownership and source module, where applicable, so administrators can understand why a file exists.

### Lifecycle actions

Show expiry, retention category, and whether a file is eligible for cleanup or archival.

### Provider capability &amp; adapter details

Built-in remote adapters for Google Drive, Dropbox, and OneDrive are provided via Composer-installed Flysystem adapters, built directly by `CloudFilesystemFactory` without requiring host Laravel driver registration. Amazon S3 remains an optional host dependency via `league/flysystem-aws-s3-v3`.

- **Google Drive, Dropbox, &amp; OneDrive presets:** Report no public or temporary URL support. They support file/folder operations and listing. Google Drive uses display paths while resolving file IDs internally; Dropbox uses Dropbox API path semantics and optional roots; OneDrive paths are relative to the selected drive root.
- **OAuth &amp; Credentials:** `StorageOAuthService` provides authorization URL generation, code exchange, and token refresh. The host remains responsible for browser routes, state storage, PKCE verification, callback validation, user authorization, and secure credential persistence via the host-owned encrypted `StorageOAuthCredentialStore` interface updating the active connection.

A unified UI does not mean unrestricted access. The UI must enforce SchoolPalm permissions and the provider's own authorization.

## 9. Security, isolation, and audit

- **Tenant/school isolation:** resolve each connection using explicit current context, including in queues, scheduled jobs, CLI commands, and tests.
- **No cross-school fallback:** a missing connection or credential for one school must never trigger use of another school's credentials.
- **Authorization:** validate the actor, module, operation, connection, and requested path before access.
- **Secrets:** encrypt credentials at rest and avoid exposing them to module code or logs.
- **Path boundaries:** prevent traversal and ensure module-owned paths cannot escape the allowed namespace.
- **Signed links:** use short-lived links and avoid public URLs for sensitive files unless explicitly authorized by policy.

## 10. Implementation phases

1. Verify and document the actual `DocumentStorage` contract.
2. Implement the ModuleBridge `StorageHost` facade and internal routing adapter.
3. Implement School Disk quota enforcement centrally.
4. Connect school settings and context-aware connection resolution.
5. Define fallback behavior per operation (including conditional `putDocument()` fallback to School Disk).
6. Integrate provider operations and capabilities (including built-in Flysystem adapters for Google Drive, Dropbox, and OneDrive).
7. Add retention, auditing, administration, and file-manager workflows.
8. Test context isolation, quota concurrency, OAuth reconnection, provider failures, and unsupported capabilities.

## 11. Decisions to finalize

- Finalizing exact exception management and fallback messaging for unavailable remote providers.
- Confirming grace periods and warning notifications when approaching School Disk quotas.
- Establishing standard UI components for OAuth re-authentication when tokens expire or are revoked.

SchoolPalm Architecture Documentation · Confidential &amp; Proprietary