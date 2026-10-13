<?php

declare(strict_types=1);

namespace WAWP\ReleaseTools\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Spec thèmes 6.6 : deux copies du code de sécurité, identiques à l'espace de noms près.
 * wawp-updater/ fait foi et n'est jamais modifié.
 */
final class SharedCodeTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function sharedFiles(): array
    {
        return [
            'signature' => ['src/class-signature.php'],
            'validateur' => ['src/class-validator.php'],
        ];
    }

    #[DataProvider('sharedFiles')]
    public function testThemeCopyIsIdenticalToThePluginOneExceptTheNamespace(string $file): void
    {
        $root = dirname(__DIR__, 2);
        $plugin = (string) file_get_contents("$root/wawp-updater/$file");
        $theme = (string) file_get_contents("$root/wawp-theme-updater/$file");
        self::assertStringContainsString("\nnamespace WAWP\ThemeUpdater;\n", $theme);
        self::assertStringNotContainsString('WAWP\Updater', $theme);
        self::assertSame($plugin, str_replace('WAWP\ThemeUpdater', 'WAWP\Updater', $theme));
    }

    public function testSameServerAndSameSigningKeys(): void
    {
        self::assertSame(\WAWP\Updater\Config::PUBLIC_KEYS, \WAWP\ThemeUpdater\Config::PUBLIC_KEYS);
        self::assertSame(\WAWP\Updater\Config::ENDPOINT, \WAWP\ThemeUpdater\Config::ENDPOINT);
        self::assertSame(\WAWP\Updater\Config::HOST, \WAWP\ThemeUpdater\Config::HOST);
    }

    public function testThemeMarkerIsOutsideThePrefixInterceptedByThePluginClient(): void
    {
        self::assertStringStartsNotWith(\WAWP\Updater\Config::ENDPOINT . '/v1/download/', \WAWP\ThemeUpdater\Config::PACKAGE_MARKER);
        self::assertStringStartsWith(\WAWP\ThemeUpdater\Config::ENDPOINT . '/', \WAWP\ThemeUpdater\Config::PACKAGE_MARKER);
    }
}
