<?php

namespace SchoolPalm\StorageApi\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use SchoolPalm\StorageApi\Contracts\DocumentStorage;
use SchoolPalm\StorageApi\Contracts\StorageContextProvider;
use SchoolPalm\StorageApi\Exceptions\StorageApiException;
use SchoolPalm\StorageApi\Facades\StorageApi;
use SchoolPalm\StorageApi\Settings\NullStorageContextProvider;
use SchoolPalm\StorageApi\Tests\TestCase;

class StorageApiServiceProviderTest extends TestCase
{
    public function test_storage_service_and_facade_resolve_without_school_context(): void
    {
        $this->assertInstanceOf(DocumentStorage::class, $this->app->make(DocumentStorage::class));
        $this->assertInstanceOf(NullStorageContextProvider::class, $this->app->make(StorageContextProvider::class));
        $this->assertInstanceOf(DocumentStorage::class, StorageApi::getFacadeRoot());
    }

    public function test_storage_operations_work_with_a_laravel_disk(): void
    {
        Storage::fake('local');
        $storage = $this->app->make(DocumentStorage::class)->forDisk('local');

        $storage->put('reports/result.txt', 'ready');

        $this->assertSame('ready', $storage->get('reports/result.txt'));
        $this->assertTrue($storage->exists('reports/result.txt'));
        $this->assertSame(['reports/result.txt'], $storage->files('reports'));
        $this->assertTrue($storage->copy('reports/result.txt', 'reports/copy.txt'));
        $this->assertTrue($storage->move('reports/copy.txt', 'reports/moved.txt'));
        $this->assertSame('ready', $storage->get('reports/moved.txt'));
        $this->assertTrue($storage->delete('reports/moved.txt'));
        $this->assertFalse($storage->exists('reports/moved.txt'));
    }

    public function test_stream_and_uploaded_file_operations_are_supported(): void
    {
        Storage::fake('local');
        $storage = $this->app->make(DocumentStorage::class)->forDisk('local');
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, 'streamed');
        rewind($stream);

        $storage->writeStream('uploads/stream.txt', $stream);
        fclose($stream);
        $read = $storage->readStream('uploads/stream.txt');
        $this->assertSame('streamed', stream_get_contents($read));
        fclose($read);

        $source = tempnam(sys_get_temp_dir(), 'storage-api-');
        file_put_contents($source, 'uploaded');
        try {
            $storage->putFile('uploads/file.txt', new \SplFileInfo($source));
            $this->assertSame('uploaded', $storage->get('uploads/file.txt'));
        } finally {
            unlink($source);
        }
    }

    public function test_invalid_paths_and_failed_reads_are_reported_without_path_values(): void
    {
        Storage::fake('local');
        $storage = $this->app->make(DocumentStorage::class)->forDisk('local');

        try {
            $storage->put('../outside.txt', 'nope');
            $this->fail('Traversal paths must be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        try {
            $storage->get('private/secret-name.txt');
            $this->fail('Missing file reads must fail.');
        } catch (StorageApiException $exception) {
            $this->assertStringNotContainsString('secret-name', $exception->getMessage());
        }
    }
}
