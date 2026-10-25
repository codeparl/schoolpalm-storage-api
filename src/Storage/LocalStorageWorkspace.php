<?php

declare(strict_types=1);

namespace SchoolPalm\StorageApi\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use InvalidArgumentException;
use RuntimeException;
use SchoolPalm\StorageApi\Contracts\StorageWorkspaceInterface;

final class LocalStorageWorkspace implements StorageWorkspaceInterface
{
    public function __construct(
        private readonly Filesystem $disk,
        private readonly string $batchUuid,
        private readonly string $basePath
    ) {}

    public function id(): string
    {
        return $this->batchUuid;
    }

    /**
     * Store serialized document source.
     */
    public function putSource(array $source): void
    {
        $this->putJson(
            'source.json',
            $source
        );
    }

    /**
     * Restore serialized document source.
     */
    public function source(): array
    {
        return $this->getJson(
            'source.json'
        );
    }

    /**
     * Resolve workspace file into physical filesystem path.
     */
    public function physicalPath(string $file): string
    {
        $storagePath = $this->path($file);

        if (! $this->disk->exists($storagePath)) {

            throw new RuntimeException(
                "Workspace file not found: {$storagePath}"
            );
        }

        if (method_exists($this->disk, 'path')) {

            return $this->disk->path(
                $storagePath
            );
        }

        throw new RuntimeException(
            'Physical paths are not supported by this storage driver.'
        );
    }

    public function putManifest(array $manifest): void
    {
        $this->putJson(
            'manifest.json',
            $manifest
        );
    }

    public function manifest(): array
    {
        return $this->getJson(
            'manifest.json'
        );
    }

    public function putPlan(array $plan): void
    {
        $this->putJson(
            'plan.json',
            $plan
        );
    }

    public function plan(): array
    {
        return $this->getJson(
            'plan.json'
        );
    }

    public function putStatus(array $status): void
    {
        $this->putJson(
            'status.json',
            $status
        );
    }

    public function updateStatus(array $status): void
    {
        $current = [];

        if ($this->statusExists()) {
            $current = $this->status();
        }

        $this->putStatus(
            array_merge(
                $current,
                $status,
                [
                    'updated_at' => now()->toISOString(),
                ]
            )
        );
    }

    public function status(): array
    {
        return $this->getJson(
            'status.json'
        );
    }

    public function statusExists(): bool
    {
        return $this->disk->exists(
            $this->path('status.json')
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Chunk storage
    |--------------------------------------------------------------------------
    */

    public function putChunk(
        int $chunk,
        mixed $data
    ): void {

        $this->putJson(
            "chunks/{$chunk}.json",
            $data
        );
    }

    public function chunk(
        int $chunk
    ): mixed {

        return $this->getJson(
            "chunks/{$chunk}.json"
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Rendered chunk artifacts
    |--------------------------------------------------------------------------
    */

    public function putRendered(
        int $chunk,
        mixed $contents,
        string $type
    ): void {

        $extension =
            $this->normalizeExtension(
                $type
            );

        $this->disk->put(
            $this->path(
                "rendered/{$chunk}.{$extension}"
            ),
            $contents
        );
    }

    public function renderedExists(
        int $chunk,
        string $type
    ): bool {

        $extension =
            $this->normalizeExtension(
                $type
            );

        return $this->disk->exists(
            $this->path(
                "rendered/{$chunk}.{$extension}"
            )
        );
    }

    public function renderedChunkPaths(
        string $type
    ): array {

        $extension =
            $this->normalizeExtension(
                $type
            );

        $directory =
            $this->path(
                'rendered'
            );

        if (! $this->disk->exists($directory)) {
            return [];
        }

        $files =
            $this->disk->files($directory);

        $chunks = [];

        foreach ($files as $file) {

            if (! str_ends_with(
                $file,
                ".{$extension}"
            )) {
                continue;
            }

            $filename =
                pathinfo(
                    $file,
                    PATHINFO_FILENAME
                );

            if (! is_numeric($filename)) {
                continue;
            }

            $chunks[(int) $filename] =
                str_replace(
                    $this->basePath.'/',
                    '',
                    $file
                );
        }

        ksort($chunks);

        return array_values($chunks);
    }

    /*
    |--------------------------------------------------------------------------
    | Final merged workspace artifact
    |--------------------------------------------------------------------------
    */

    public function putFinal(
        mixed $contents,
        string $type
    ): void {

        $extension =
            $this->normalizeExtension(
                $type
            );

        $this->disk->put(
            $this->path(
                "final/document.{$extension}"
            ),
            $contents
        );
    }

    public function finalExists(
        string $type
    ): bool {

        $extension =
            $this->normalizeExtension(
                $type
            );

        return $this->disk->exists(
            $this->path(
                "final/document.{$extension}"
            )
        );
    }

    public function final(
        string $type
    ): string {

        $extension =
            $this->normalizeExtension(
                $type
            );

        $path =
            $this->path(
                "final/document.{$extension}"
            );

        if (! $this->disk->exists($path)) {

            throw new RuntimeException(
                "Final document not found at: {$path}"
            );
        }

        return $this->disk->get($path);
    }

    public function cleanup(): void
    {
        $this->disk->deleteDirectory(
            $this->basePath
        );
    }

    private function normalizeExtension(
        string $type
    ): string {
        $extension = match (strtolower($type)) {

            /*
        |--------------------------------------------------------------------------
        | Documents
        |--------------------------------------------------------------------------
        */

            'pdf' => 'pdf',

            'spreadsheet',
            'xlsx',
            'xls' => 'xlsx',

            'word',
            'docx' => 'docx',

            'csv' => 'csv',

            /*
        |--------------------------------------------------------------------------
        | Images
        |--------------------------------------------------------------------------
        */

            'image',
            'img' => config(
                'document-builder.drivers.image.extension',
                'png'
            ),

            'jpg',
            'jpeg' => 'jpg',

            'png' => 'png',

            'webp' => 'webp',

            'avif' => 'avif',

            'gif' => 'gif',

            'svg' => 'svg',

            'bmp' => 'bmp',

            'tif',
            'tiff' => 'tiff',

            /*
        |--------------------------------------------------------------------------
        | Fallback
        |--------------------------------------------------------------------------
        */

            default => strtolower($type),
        };

        if (preg_match('/^[a-z0-9]+$/', $extension) !== 1) {
            throw new InvalidArgumentException('Workspace artifact type is invalid.');
        }

        return $extension;
    }

    private function putJson(
        string $file,
        mixed $data
    ): void {

        $this->disk->put(
            $this->path($file),
            json_encode(
                $data,
                JSON_THROW_ON_ERROR
            )
        );
    }

    private function getJson(
        string $file
    ): mixed {

        $path =
            $this->path($file);

        if (! $this->disk->exists($path)) {

            throw new RuntimeException(
                "Workspace file not found: {$path}"
            );
        }

        return json_decode(
            $this->disk->get($path),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    private function path(
        string $path
    ): string {
        if (str_contains($path, "\0")) {
            throw new InvalidArgumentException('Workspace path contains invalid characters.');
        }

        $path = str_replace('\\', '/', $path);
        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path) === 1) {
            throw new InvalidArgumentException('Workspace paths must be relative.');
        }

        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('Workspace path contains an invalid path segment.');
            }
        }

        return "{$this->basePath}/".implode('/', $segments);
    }
}
