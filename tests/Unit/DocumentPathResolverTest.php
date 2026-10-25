<?php

namespace SchoolPalm\StorageApi\Tests\Unit;

use InvalidArgumentException;
use SchoolPalm\StorageApi\Contracts\StorageContextProvider;
use SchoolPalm\StorageApi\Settings\StorageContext;
use SchoolPalm\StorageApi\Storage\DocumentPathResolver;
use SchoolPalm\StorageApi\Tests\TestCase;

class DocumentPathResolverTest extends TestCase
{
    public function test_no_context_uses_relative_paths_without_requiring_tenant(): void
    {
        $resolver = new DocumentPathResolver;

        $this->assertSame('reports/result.pdf', $resolver->resolve('reports/result.pdf'));
    }

    public function test_contexts_are_scoped_and_explicit_context_overrides_automatic_context(): void
    {
        $resolver = new DocumentPathResolver;

        $this->assertSame(
            'tenant-a/schools/school-a/reports/result.pdf',
            $resolver->withContext('tenant-a', 'school-a')->resolve('reports/result.pdf'),
        );
        $this->assertSame(
            'applications/app-a/tenants/tenant-b/schools/school-b/file.txt',
            $resolver->withStorageContext(new StorageContext(
                applicationId: 'app-a',
                tenantId: 'tenant-b',
                schoolId: 'school-b',
            ))->resolve('file.txt'),
        );
    }

    public function test_explicit_context_takes_precedence_over_automatic_context(): void
    {
        $provider = new FixedStorageContextProvider(new StorageContext(tenantId: 'automatic', schoolId: 'auto-school'));
        $resolver = new DocumentPathResolver($provider);

        $this->assertSame('automatic/schools/auto-school/file.txt', $resolver->resolve('file.txt'));
        $this->assertSame(
            'explicit/schools/explicit-school/file.txt',
            $resolver->withContext('explicit', 'explicit-school')->resolve('file.txt'),
        );
    }

    public function test_path_traversal_and_absolute_paths_are_rejected(): void
    {
        $resolver = new DocumentPathResolver;

        $this->expectException(InvalidArgumentException::class);
        $resolver->resolve('../outside.txt');
    }
}

final class FixedStorageContextProvider implements StorageContextProvider
{
    public function __construct(private readonly StorageContext $context) {}

    public function current(): ?StorageContext
    {
        return $this->context;
    }
}
