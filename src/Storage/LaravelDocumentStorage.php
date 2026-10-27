<?php

declare(strict_types=1);

namespace SchoolPalm\StorageApi\Storage;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Filesystem\Factory as StorageFactory;
use Illuminate\Filesystem\FilesystemAdapter;
use RuntimeException;
use SchoolPalm\StorageApi\Contracts\DocumentContent;
use SchoolPalm\StorageApi\Contracts\DocumentStorage;
use SchoolPalm\StorageApi\Contracts\StorageCapabilities;
use SchoolPalm\StorageApi\Contracts\StorageWorkspaceInterface;
use SchoolPalm\StorageApi\Exceptions\InvalidStorageConfigurationException;
use SchoolPalm\StorageApi\Exceptions\StorageApiException;
use SchoolPalm\StorageApi\Exceptions\UnsupportedStorageOperationException;
use SchoolPalm\StorageApi\Exceptions\UnsupportedStorageProviderException;
use SchoolPalm\StorageApi\Providers\StorageProviderRegistry;
use SchoolPalm\StorageApi\Settings\StorageContext;
use SchoolPalm\StorageApi\Settings\StorageSettings;
use SplFileInfo;
use Throwable;

/**
 * Implements the provider-neutral document API over Laravel filesystem disks.
 */
final class LaravelDocumentStorage implements DocumentStorage
{
    private readonly StorageProviderRegistry $providerRegistry;

    private readonly ?StorageContext $explicitContext;

    private readonly ?string $connectionName;

    public function __construct(
        private readonly StorageFactory $storageFactory,
        private readonly DocumentPathResolver $pathResolver,
        private readonly DiskBuilder $diskBuilder,
        private readonly ?string $explicitDisk = null,
        private readonly string $fallbackDisk = 'local',
        ?string $tenantId = null,
        ?string $schoolId = null,
        ?StorageProviderRegistry $providerRegistry = null,
        ?StorageContext $explicitContext = null,
        ?string $connectionName = null,
    ) {
        $this->providerRegistry = $providerRegistry ?? new StorageProviderRegistry;
        $this->explicitContext = $explicitContext
            ?? ($tenantId !== null || $schoolId !== null
                ? new StorageContext(tenantId: $tenantId, schoolId: $schoolId)
                : null);
        $this->connectionName = $connectionName;
    }

    public function forContext(?string $tenantId, ?string $schoolId): static
    {
        return $this->forStorageContext(new StorageContext(
            tenantId: $tenantId,
            schoolId: $schoolId,
        ));
    }

    public function forStorageContext(StorageContext $context): static
    {
        return new self(
            storageFactory: $this->storageFactory,
            pathResolver: $this->pathResolver->withStorageContext($context),
            diskBuilder: $this->diskBuilder,
            explicitDisk: $this->explicitDisk,
            fallbackDisk: $this->fallbackDisk,
            tenantId: $context->tenantId,
            schoolId: $context->schoolId,
            providerRegistry: $this->providerRegistry,
            explicitContext: $context,
            connectionName: $this->connectionName,
        );
    }

    public function forDisk(string $disk): static
    {
        if ($disk === '') {
            throw new \InvalidArgumentException('Disk name must not be empty.');
        }

        return new self(
            storageFactory: $this->storageFactory,
            pathResolver: $this->pathResolver,
            diskBuilder: $this->diskBuilder,
            explicitDisk: $disk,
            fallbackDisk: $this->fallbackDisk,
            tenantId: $this->explicitContext?->tenantId,
            schoolId: $this->explicitContext?->schoolId,
            providerRegistry: $this->providerRegistry,
            explicitContext: $this->explicitContext,
        );
    }

    public function forConnection(string $connection): static
    {
        if ($connection === '') {
            throw new \InvalidArgumentException('Connection name must not be empty.');
        }

        return new self(
            storageFactory: $this->storageFactory,
            pathResolver: $this->pathResolver,
            diskBuilder: $this->diskBuilder,
            fallbackDisk: $this->fallbackDisk,
            tenantId: $this->explicitContext?->tenantId,
            schoolId: $this->explicitContext?->schoolId,
            providerRegistry: $this->providerRegistry,
            explicitContext: $this->explicitContext,
            connectionName: $connection,
        );
    }

    public function currentDisk(): string
    {
        if ($this->explicitDisk !== null) {
            return $this->explicitDisk;
        }

        $settings = $this->resolveSettings();
        if ($this->connectionName !== null && $settings === null) {
            throw new InvalidStorageConfigurationException(sprintf(
                'Storage connection [%s] is not configured.',
                $this->connectionName,
            ));
        }

        return $settings?->diskName ?? $this->fallbackDisk;
    }

    public function capabilities(): StorageCapabilities
    {
        $settings = $this->resolveSettings();
        $provider = $settings?->provider;

        if ($provider === null && $this->explicitDisk !== null) {
            $provider = (string) config("filesystems.disks.{$this->explicitDisk}.driver", '');
        }

        if ($provider === null || $provider === '') {
            $provider = (string) config("filesystems.disks.{$this->fallbackDisk}.driver", 'local');
        }

        try {
            $capabilities = $this->providerRegistry->definition($provider)->capabilities;
        } catch (UnsupportedStorageProviderException) {
            return StorageCapabilities::generic($provider);
        }

        $configuration = $this->resolveSettings()?->config
            ?? (array) config('filesystems.disks.'.($this->explicitDisk ?? $this->fallbackDisk), []);

        if ($provider === 'local') {
            return StorageCapabilities::local(
                supportsPublicUrl: ($configuration['visibility'] ?? 'private') === 'public',
                supportsTemporaryUrl: ($configuration['serve'] ?? false) === true,
            );
        }

        if ($provider === 's3' && ($configuration['visibility'] ?? 'private') !== 'public') {
            return new StorageCapabilities(
                provider: $capabilities->provider,
                supportsStreamRead: $capabilities->supportsStreamRead,
                supportsStreamWrite: $capabilities->supportsStreamWrite,
                supportsPublicUrl: false,
                supportsTemporaryUrl: $capabilities->supportsTemporaryUrl,
                supportsPhysicalPath: $capabilities->supportsPhysicalPath,
                supportsDirectories: $capabilities->supportsDirectories,
                supportsAtomicMove: $capabilities->supportsAtomicMove,
                supportsServerSideCopy: $capabilities->supportsServerSideCopy,
                maxFileSize: $capabilities->maxFileSize,
                chunkSize: $capabilities->chunkSize,
                retryAttempts: $capabilities->retryAttempts,
                retryBackoffMs: $capabilities->retryBackoffMs,
            );
        }

        return $capabilities;
    }

    public function exists(string $path): bool
    {
        $resolved = $this->resolve($path);

        return $this->operate('exists', fn (): bool => $this->getDisk()->exists($resolved));
    }

    public function size(string $path): ?int
    {
        $resolved = $this->resolve($path);

        return $this->operate('size', function () use ($resolved): ?int {
            $disk = $this->getDisk();

            return $disk->exists($resolved) ? $disk->size($resolved) : null;
        });
    }

    public function mimeType(string $path): ?string
    {
        $resolved = $this->resolve($path);

        return $this->operate('mimeType', function () use ($resolved): ?string {
            $value = $this->getDisk()->mimeType($resolved);

            return is_string($value) ? $value : null;
        });
    }

    public function lastModified(string $path): ?int
    {
        $resolved = $this->resolve($path);

        return $this->operate('lastModified', function () use ($resolved): ?int {
            $value = $this->getDisk()->lastModified($resolved);

            return is_numeric($value) ? (int) $value : null;
        });
    }

    public function files(string $directory = ''): array
    {
        $this->requireDirectories();
        $resolved = $this->resolveDirectory($directory);

        return $this->operate('files', fn (): array => $this->getDisk()->files($resolved));
    }

    public function allFiles(string $directory = ''): array
    {
        $this->requireDirectories();
        $resolved = $this->resolveDirectory($directory);

        return $this->operate('allFiles', fn (): array => $this->getDisk()->allFiles($resolved));
    }

    public function directories(string $directory = ''): array
    {
        $this->requireDirectories();
        $resolved = $this->resolveDirectory($directory);

        return $this->operate('directories', fn (): array => $this->getDisk()->directories($resolved));
    }

    public function allDirectories(string $directory = ''): array
    {
        $this->requireDirectories();
        $resolved = $this->resolveDirectory($directory);

        return $this->operate('allDirectories', fn (): array => $this->getDisk()->allDirectories($resolved));
    }

    public function get(string $path): string
    {
        $resolved = $this->resolve($path);

        return $this->operate('get', function () use ($resolved): string {
            $contents = $this->getDisk()->get($resolved);
            if (! is_string($contents)) {
                throw new RuntimeException('Filesystem did not return file contents.');
            }

            return $contents;
        });
    }

    public function readStream(string $path): mixed
    {
        $resolved = $this->resolve($path);

        return $this->operate('readStream', function () use ($resolved): mixed {
            $stream = $this->getDisk()->readStream($resolved);
            if (! is_resource($stream)) {
                throw new RuntimeException('Filesystem did not return a readable stream.');
            }

            return $stream;
        });
    }

    public function put(string $path, mixed $contents): void
    {
        $resolved = $this->resolve($path);

        $this->operate('put', function () use ($resolved, $contents): void {
            if (! $this->getDisk()->put($resolved, $contents)) {
                throw new RuntimeException('Filesystem write failed.');
            }
        });
    }

    public function putFile(string $path, SplFileInfo $file): void
    {
        $localPath = $file->getRealPath();
        if ($localPath === false || ! is_file($localPath) || ! is_readable($localPath)) {
            throw new \InvalidArgumentException('The uploaded file is not readable.');
        }

        $this->putFromPath($path, $localPath);
    }

    public function writeStream(string $path, mixed $stream): void
    {
        $resolved = $this->resolve($path);
        $mode = is_resource($stream) ? (string) (stream_get_meta_data($stream)['mode'] ?? '') : '';
        if (! is_resource($stream) || (! str_contains($mode, 'r') && ! str_contains($mode, '+'))) {
            throw new \InvalidArgumentException('writeStream() expects a readable stream resource.');
        }

        $this->operate('writeStream', function () use ($resolved, $stream): void {
            if (! $this->getDisk()->writeStream($resolved, $stream)) {
                throw new RuntimeException('Filesystem stream write failed.');
            }
        });
    }

    public function putFromPath(string $path, string $localPath): void
    {
        if (! is_file($localPath) || ! is_readable($localPath)) {
            throw new \InvalidArgumentException('The source file is not readable.');
        }

        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new \InvalidArgumentException('The source file could not be opened.');
        }

        try {
            $this->writeStream($path, $stream);
        } finally {
            fclose($stream);
        }
    }

    public function copyToPath(string $path, string $localPath): void
    {
        $stream = $this->readStream($path);
        $output = fopen($localPath, 'wb');
        if ($output === false) {
            fclose($stream);
            throw new \InvalidArgumentException('The destination file could not be opened.');
        }

        try {
            if (stream_copy_to_stream($stream, $output) === false) {
                throw new StorageApiException('The file could not be copied to the local destination.');
            }
        } finally {
            fclose($stream);
            fclose($output);
        }
    }

    public function delete(string $path): bool
    {
        $resolved = $this->resolve($path);

        return $this->operate('delete', fn (): bool => $this->getDisk()->delete($resolved));
    }

    public function deleteDirectory(string $directory): bool
    {
        $this->requireDirectories();
        $resolved = $this->resolve($directory);

        return $this->operate('deleteDirectory', fn (): bool => $this->getDisk()->deleteDirectory($resolved));
    }

    public function copy(string $from, string $to): bool
    {
        $source = $this->resolve($from);
        $destination = $this->resolve($to);

        return $this->operate('copy', fn (): bool => $this->getDisk()->copy($source, $destination));
    }

    public function move(string $from, string $to): bool
    {
        $source = $this->resolve($from);
        $destination = $this->resolve($to);

        return $this->operate('move', fn (): bool => $this->getDisk()->move($source, $destination));
    }

    public function publicUrl(string $path): ?string
    {
        if (! $this->capabilities()->supportsPublicUrl) {
            throw new UnsupportedStorageOperationException('The configured provider does not support public URLs.');
        }

        $resolved = $this->resolve($path);

        return $this->operate('publicUrl', fn (): string => $this->getDisk()->url($resolved));
    }

    public function temporaryUrl(
        string $path,
        ?DateTimeInterface $expiresAt = null,
    ): ?string {
        if (! $this->capabilities()->supportsTemporaryUrl) {
            throw new UnsupportedStorageOperationException('The configured provider does not support temporary URLs.');
        }

        $resolved = $this->resolve($path);
        $expiresAt ??= new DateTimeImmutable('+1 hour');

        return $this->operate('temporaryUrl', fn (): string => $this->getDisk()->temporaryUrl($resolved, $expiresAt));
    }

    public function batchWorkspace(string $batchUuid): StorageWorkspaceInterface
    {
        if (! $this->capabilities()->supportsPhysicalPath) {
            throw new UnsupportedStorageOperationException('Document workspaces require a provider with physical path support.');
        }

        return new LocalStorageWorkspace(
            $this->getDisk(),
            $batchUuid,
            $this->pathResolver->resolveBatchPath($batchUuid),
        );
    }

    public function putContent(string $path, DocumentContent $content): void
    {
        $resolved = $this->resolve($path);

        $this->operate('putContent', function () use ($content, $resolved): void {
            $disk = $this->getDisk();
            $content->writeTo(static function (string $data) use ($disk, $resolved): void {
                if (! $disk->put($resolved, $data)) {
                    throw new RuntimeException('Filesystem content write failed.');
                }
            });
        });
    }

    public function physicalPath(string $path): ?string
    {
        if (! $this->capabilities()->supportsPhysicalPath) {
            return null;
        }

        $resolved = $this->resolve($path);

        return $this->operate('physicalPath', function () use ($resolved): ?string {
            $path = $this->getDisk()->path($resolved);

            return is_string($path) ? $path : null;
        });
    }

    public function invalidate(?string $connection = null): void
    {
        $this->diskBuilder->invalidate($this->currentContext(), $connection ?? $this->connectionName);
    }

    private function getDisk(): FilesystemAdapter
    {
        if ($this->explicitDisk !== null) {
            try {
                $disk = $this->storageFactory->disk($this->explicitDisk);
            } catch (Throwable) {
                throw new StorageApiException('The requested filesystem disk is unavailable.');
            }

            if (! $disk instanceof FilesystemAdapter) {
                throw new StorageApiException('The requested filesystem disk is not supported by this package.');
            }

            return $disk;
        }

        $context = $this->currentContext();
        $settings = $this->diskBuilder->settingsFor(
            $context?->tenantId,
            $context?->schoolId,
            $this->connectionName,
            $context,
        );

        if ($settings !== null) {
            return $this->diskBuilder->build($settings, $context);
        }

        if ($this->connectionName !== null) {
            throw new InvalidStorageConfigurationException(sprintf(
                'Storage connection [%s] is not configured.',
                $this->connectionName,
            ));
        }

        try {
            $disk = $this->storageFactory->disk($this->fallbackDisk);
        } catch (Throwable) {
            throw new StorageApiException('The default filesystem disk is unavailable.');
        }

        if (! $disk instanceof FilesystemAdapter) {
            throw new StorageApiException('The default filesystem disk is not supported by this package.');
        }

        return $disk;
    }

    private function resolveSettings(): ?StorageSettings
    {
        $context = $this->currentContext();

        return $this->diskBuilder->settingsFor(
            $context?->tenantId,
            $context?->schoolId,
            $this->connectionName,
            $context,
        );
    }

    private function currentContext(): ?StorageContext
    {
        return $this->explicitContext ?? $this->pathResolver->currentContext();
    }

    private function resolve(string $path): string
    {
        return $this->pathResolver->resolve($path);
    }

    private function resolveDirectory(string $directory): string
    {
        return $directory === '' ? $this->pathResolver->resolveRoot() : $this->resolve($directory);
    }

    private function requireDirectories(): void
    {
        if (! $this->capabilities()->supportsDirectories) {
            throw new UnsupportedStorageOperationException('The configured provider does not support directory operations.');
        }
    }

    private function operate(string $operation, callable $callback): mixed
    {
        $capabilities = $this->capabilities();
        $attempts = max(1, $capabilities->retryAttempts + 1);

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                return $callback();
            } catch (StorageApiException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                if ($attempt < $attempts && $this->isTransient($exception)) {
                    usleep($capabilities->retryBackoffMs * 1000 * (2 ** ($attempt - 1)));

                    continue;
                }

                throw new StorageApiException(sprintf('Storage operation [%s] failed.', $operation));
            }
        }

        throw new StorageApiException(sprintf('Storage operation [%s] failed.', $operation));
    }

    private function isTransient(Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        foreach (['rate limit', 'throttled', 'timeout', 'connection reset', 'connection refused', '500', '502', '503', '504', '429'] as $signal) {
            if (str_contains($message, $signal)) {
                return true;
            }
        }

        return false;
    }
}
