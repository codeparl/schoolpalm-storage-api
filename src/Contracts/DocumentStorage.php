<?php

declare(strict_types=1);

namespace SchoolPalm\StorageApi\Contracts;

use DateTimeInterface;
use SchoolPalm\StorageApi\Settings\StorageContext;
use SplFileInfo;

/**
 * Tenant-scoped, disk-agnostic document storage.
 *
 * All paths accepted by this interface are **storage-relative**. They
 * are resolved internally against the active tenant/school context
 * and disk. Callers MUST NOT pass pre-resolved paths.
 *
 * Implementations MUST be immutable with respect to context:
 * forContext() and forDisk() return new instances and never mutate
 * the receiver.
 *
 * Implementations MUST be streaming-capable: readStream(),
 * writeStream(), putFromPath() and copyToPath() are required.
 *
 * Implementations MUST declare their capabilities via capabilities()
 * so callers can make safe decisions about which methods to use on
 * which provider.
 */
interface DocumentStorage
{
    // ---------------------------------------------------------------- context

    public function forContext(?string $tenantId, ?string $schoolId): static;

    public function forStorageContext(StorageContext $context): static;

    public function forDisk(string $disk): static;

    public function forConnection(string $connection): static;

    public function currentDisk(): string;

    /**
     * Return the capability set of the current disk.
     *
     * Callers should consult this before invoking optional features
     * (publicUrl, temporaryUrl, physicalPath) or performing large
     * uploads. The capabilities reflect the actual disk, not a
     * theoretical maximum.
     */
    public function capabilities(): StorageCapabilities;

    // -------------------------------------------------------------- metadata

    public function exists(string $path): bool;

    public function size(string $path): ?int;

    public function mimeType(string $path): ?string;

    public function lastModified(string $path): ?int;

    /** @return list<string> */
    public function files(string $directory = ''): array;

    /** @return list<string> */
    public function allFiles(string $directory = ''): array;

    /** @return list<string> */
    public function directories(string $directory = ''): array;

    /** @return list<string> */
    public function allDirectories(string $directory = ''): array;
    // ---------------------------------------------------------------- read

    /**
     * Read the entire file into memory.
     *
     * Convenience wrapper. Prefer readStream() for anything that may
     * exceed a few MB. Throws when the file does not exist.
     */
    public function get(string $path): string;

    /**
     * Open a read stream. The caller owns the stream and MUST close it.
     *
     * @return resource
     */
    public function readStream(string $path): mixed;

    // --------------------------------------------------------------- write

    /**
     * Store the given contents, replacing any existing file.
     */
    public function put(string $path, mixed $contents): void;

    public function putFile(string $path, SplFileInfo $file): void;

    /**
     * Write from a caller-owned readable stream. The caller retains
     * ownership of the stream and is responsible for closing it.
     *
     * @param  resource  $stream
     */
    public function writeStream(string $path, mixed $stream): void;

    /**
     * Upload a local file by path. Streams — never loads the file
     * into memory as a string.
     *
     * Implementations SHOULD use chunked upload when the file
     * exceeds the provider's chunk size.
     */
    public function putFromPath(string $path, string $localPath): void;

    /**
     * Stream a stored file down to a local path. Streams — never
     * materialises the file as a string.
     */
    public function copyToPath(string $path, string $localPath): void;

    // -------------------------------------------------------------- delete

    public function delete(string $path): bool;

    public function deleteDirectory(string $directory): bool;

    // ---------------------------------------------------------- copy / move

    public function copy(string $from, string $to): bool;

    public function move(string $from, string $to): bool;

    // ---------------------------------------------------------------- urls

    /**
     * A permanent public URL for the file, or null when the disk
     * does not expose one.
     */
    public function publicUrl(string $path): ?string;

    /**
     * A time-limited signed URL for the file, or null when the disk
     * does not support signed URLs.
     *
     * @param  DateTimeInterface|null  $expiresAt  When the URL should
     *                                             expire. Null = provider
     *                                             default (typically 1 hour
     *                                             for S3, 15 min for
     *                                             OneDrive, etc.).
     */
    public function temporaryUrl(
        string $path,
        ?DateTimeInterface $expiresAt = null,
    ): ?string;

    // -------------------------------------------- document-builder extensions

    /**
     * Initialize an isolated storage workspace for a document
     * generation batch.
     */
    public function batchWorkspace(string $batchUuid): StorageWorkspaceInterface;

    /**
     * Write a DocumentContent payload to a path.
     */
    public function putContent(string $path, DocumentContent $content): void;

    /**
     * Return the physical filesystem path when the disk supports it,
     * or null otherwise (e.g. S3, GDrive, Dropbox).
     */
    public function physicalPath(string $path): ?string;

    public function invalidate(?string $connection = null): void;
}
