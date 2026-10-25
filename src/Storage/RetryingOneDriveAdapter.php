<?php

declare(strict_types=1);

namespace SchoolPalm\StorageApi\Storage;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Justus\FlysystemOneDrive\OneDriveAdapter;
use League\Flysystem\Config;
use RuntimeException;

final class RetryingOneDriveAdapter extends OneDriveAdapter
{
    public function writeStream(string $path, $contents, ?Config $config = null): void
    {
        $path = trim($path, '/');
        $this->ensureValidPath($path);

        $metadata = fstat($contents);
        if ($metadata === false || ! isset($metadata['size'])) {
            throw new RuntimeException('The OneDrive upload stream size could not be determined.');
        }

        $fileSize = (int) $metadata['size'];
        if ($fileSize === 0) {
            $this->write($path, '', $config);

            return;
        }

        $uploadSession = $this->createUploadSession($path);
        $uploadUrl = $uploadSession->getUploadUrl();
        if (! is_string($uploadUrl) || $uploadUrl === '') {
            throw new RuntimeException('The OneDrive upload session did not return an upload URL.');
        }

        $configuration = $config ?? new Config;
        $chunkSize = (int) $configuration->withDefaults($this->options)->get('chunk_size');
        $offset = 0;

        while (($chunk = fread($contents, $chunkSize)) !== false && $chunk !== '') {
            $this->uploadChunk($uploadUrl, $fileSize, $chunk, $offset);
            $offset += strlen($chunk);
        }
    }

    private function uploadChunk(string $uploadUrl, int $fileSize, string $chunk, int $firstByte): void
    {
        $lastByte = $firstByte + strlen($chunk) - 1;
        $finalChunk = $lastByte === $fileSize - 1;

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $response = Http::withHeaders([
                'Content-Range' => "bytes $firstByte-$lastByte/$fileSize",
                'Content-Length' => (string) strlen($chunk),
            ])
                ->withBody($chunk, 'application/octet-stream')
                ->timeout($this->options['request_timeout'])
                ->put($uploadUrl);

            if ($this->isRetryable($response->status())) {
                if ($attempt === 9) {
                    throw new RuntimeException('The OneDrive upload failed after repeated transient errors.');
                }

                $this->waitBeforeRetry($response, $attempt);

                continue;
            }

            $expectedStatuses = $finalChunk ? [200, 201] : [202];
            if (! in_array($response->status(), $expectedStatuses, true)) {
                throw new RuntimeException('The OneDrive upload returned an unexpected response.');
            }

            return;
        }
    }

    private function isRetryable(int $status): bool
    {
        return $status === 408 || $status === 429 || $status >= 500;
    }

    private function waitBeforeRetry(Response $response, int $attempt): void
    {
        $retryAfter = $response->header('Retry-After');
        if (is_string($retryAfter) && is_numeric($retryAfter)) {
            $delay = min(60, max(0, (int) $retryAfter));
        } else {
            $timestamp = is_string($retryAfter) ? strtotime($retryAfter) : false;
            $delay = $timestamp === false
                ? min(30, 1 << min($attempt, 4))
                : min(60, max(0, $timestamp - time()));
        }

        if ($delay > 0) {
            sleep($delay);
        }
    }
}
