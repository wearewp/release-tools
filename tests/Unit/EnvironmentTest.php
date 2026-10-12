<?php

declare(strict_types=1);

namespace WAWP\ReleaseTools\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class EnvironmentTest extends TestCase
{
    public function testRequiredExtensionsAreLoaded(): void
    {
        foreach (['json', 'sodium', 'zip', 'curl'] as $extension) {
            self::assertTrue(extension_loaded($extension), "Extension manquante : $extension");
        }
    }
}
