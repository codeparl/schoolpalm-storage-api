<?php

declare(strict_types=1);

namespace SchoolPalm\StorageApi\Contracts;

/**
 * Capability declaration for a DocumentStorage implementation.
 *
 * Capabilities are DECLARED, not probed. Probing requires network
 * round-trips per check, which makes every operation slow. Each
 * preset mirrors the real behaviour of the underlying driver.
 *
 * Callers use capabilities to pick safe strategies:
 *   - Check supportsPublicUrl before calling publicUrl()
 *   - Check supportsTemporaryUrl before calling temporaryUrl()
 *   - Check maxFileSize before attempting a large upload
 *   - Check chunkSize to decide whether to stream in chunks
 *
 * Absent capabilities default to false / null / 0, meaning "assume
 * the feature is unavailable". That makes callers safe by default.
 */
final readonly class StorageCapabilities
{
    /**
     * @param  string  $provider  Stable identifier: 'local', 's3',
     *                            'gdrive', 'dropbox', 'onedrive'.
     * @param  bool  $supportsStreamRead  readStream() actually streams.
     * @param  bool  $supportsStreamWrite  writeStream() actually streams.
     * @param  bool  $supportsPublicUrl  publicUrl() returns a real URL.
     * @param  bool  $supportsTemporaryUrl  temporaryUrl() returns a signed URL.
     * @param  bool  $supportsPhysicalPath  physicalPath() returns a filesystem path.
     * @param  bool  $supportsDirectories  Directory operations are meaningful.
     * @param  bool  $supportsAtomicMove  move() is atomic (or near-atomic).
     * @param  bool  $supportsServerSideCopy
     *                                        copy() runs server-side without
     *                                        a download+upload round-trip.
     * @param  int|null  $maxFileSize  Per-file limit in bytes, or null.
     * @param  int|null  $chunkSize  Required chunk size in bytes for
     *                               large uploads, or null if the
     *                               provider accepts any size.
     * @param  int  $retryAttempts  Retries after the first failure.
     *                              0 means "never retry".
     * @param  int  $retryBackoffMs  Base backoff in milliseconds,
     *                               doubled per attempt.
     */
    public function __construct(
        public string $provider,
        public bool $supportsStreamRead,
        public bool $supportsStreamWrite,
        public bool $supportsPublicUrl,
        public bool $supportsTemporaryUrl,
        public bool $supportsPhysicalPath,
        public bool $supportsDirectories,
        public bool $supportsAtomicMove,
        public bool $supportsServerSideCopy,
        public ?int $maxFileSize,
        public ?int $chunkSize,
        public int $retryAttempts,
        public int $retryBackoffMs,
    ) {}

    // ---------------------------------------------------------- presets

    public static function local(
        bool $supportsPublicUrl = false,
        bool $supportsTemporaryUrl = false,
    ): self {
        return new self(
            provider: 'local',
            supportsStreamRead: true,
            supportsStreamWrite: true,
            supportsPublicUrl: $supportsPublicUrl,
            supportsTemporaryUrl: $supportsTemporaryUrl,
            supportsPhysicalPath: true,
            supportsDirectories: true,
            supportsAtomicMove: true,
            supportsServerSideCopy: true,
            maxFileSize: null,
            chunkSize: null,
            retryAttempts: 0,
            retryBackoffMs: 0,
        );
    }

    public static function s3(): self
    {
        return new self(
            provider: 's3',
            supportsStreamRead: true,
            supportsStreamWrite: true,
            supportsPublicUrl: true,
            supportsTemporaryUrl: true,
            supportsPhysicalPath: false,
            supportsDirectories: true,
            supportsAtomicMove: false,
            supportsServerSideCopy: true,
            maxFileSize: 5_000_000_000_000,   // 5 TB
            chunkSize: 100 * 1024 * 1024,     // 100 MB parts
            retryAttempts: 3,
            retryBackoffMs: 250,
        );
    }

    public static function gdrive(): self
    {
        return new self(
            provider: 'gdrive',
            supportsStreamRead: true,
            supportsStreamWrite: true,
            supportsPublicUrl: false,
            supportsTemporaryUrl: false,
            supportsPhysicalPath: false,
            supportsDirectories: true,
            supportsAtomicMove: true,
            supportsServerSideCopy: true,
            maxFileSize: 5 * 1024 * 1024 * 1024 * 1024,  // 5 TB
            chunkSize: 100 * 1024 * 1024,
            retryAttempts: 3,
            retryBackoffMs: 1000,
        );
    }

    public static function dropbox(): self
    {
        return new self(
            provider: 'dropbox',
            supportsStreamRead: true,
            supportsStreamWrite: true,
            supportsPublicUrl: false,
            supportsTemporaryUrl: false,
            supportsPhysicalPath: false,
            supportsDirectories: true,
            supportsAtomicMove: true,
            supportsServerSideCopy: true,
            maxFileSize: 350 * 1024 * 1024 * 1024,
            chunkSize: 150 * 1024 * 1024,
            retryAttempts: 3,
            retryBackoffMs: 0,
        );
    }

    public static function onedrive(): self
    {
        return new self(
            provider: 'onedrive',
            supportsStreamRead: true,
            supportsStreamWrite: true,
            supportsPublicUrl: false,
            supportsTemporaryUrl: false,
            supportsPhysicalPath: false,
            supportsDirectories: true,
            supportsAtomicMove: true,
            supportsServerSideCopy: true,
            maxFileSize: 250 * 1024 * 1024 * 1024,  // 250 GB
            chunkSize: 10 * 1024 * 1024,
            retryAttempts: 9,
            retryBackoffMs: 1000,
        );
    }

    /**
     * Conservative fallback for unknown providers. Assumes the
     * minimum: it streams, but does not promise URLs, paths,
     * directory operations, or retries.
     */
    public static function generic(string $provider): self
    {
        return new self(
            provider: $provider,
            supportsStreamRead: true,
            supportsStreamWrite: true,
            supportsPublicUrl: false,
            supportsTemporaryUrl: false,
            supportsPhysicalPath: false,
            supportsDirectories: false,
            supportsAtomicMove: false,
            supportsServerSideCopy: false,
            maxFileSize: null,
            chunkSize: null,
            retryAttempts: 0,
            retryBackoffMs: 250,
        );
    }
}
