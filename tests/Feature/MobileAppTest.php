<?php

namespace Tests\Feature;

use Tests\TestCase;

class MobileAppTest extends TestCase
{
    public function test_installation_assets_are_consistent_and_available_without_private_content(): void
    {
        $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('/', $manifest['scope']);
        $this->assertSame('/', $manifest['start_url']);
        foreach ($manifest['icons'] as $icon) {
            $size = getimagesize(public_path(ltrim($icon['src'], '/')));
            $this->assertSame($icon['sizes'], $size[0].'x'.$size[1]);
            $this->assertSame('image/png', $size['mime']);
        }
        $this->assertFileExists(public_path('apple-touch-icon.png'));
        $this->assertFileExists(public_path('offline.html'));
        $this->assertStringContainsString('rel="manifest"', file_get_contents(resource_path('views/app.blade.php')));
    }
}
