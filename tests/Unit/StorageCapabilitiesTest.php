<?php

namespace SchoolPalm\StorageApi\Tests\Unit;

use SchoolPalm\StorageApi\Contracts\StorageCapabilities;
use SchoolPalm\StorageApi\Tests\TestCase;

class StorageCapabilitiesTest extends TestCase
{
    public function test_generic_provider_is_conservative_and_local_urls_are_opt_in(): void
    {
        $generic = StorageCapabilities::generic('custom');
        $local = StorageCapabilities::local();
        $publicLocal = StorageCapabilities::local(supportsPublicUrl: true);

        $this->assertFalse($generic->supportsPublicUrl);
        $this->assertFalse($local->supportsPublicUrl);
        $this->assertTrue($publicLocal->supportsPublicUrl);
    }
}
