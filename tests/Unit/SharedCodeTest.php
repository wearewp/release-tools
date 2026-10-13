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

    /** @return array<string, array{string}> */
    public static function sharedMethods(): array
    {
        return [
            'read' => ['read'],
            'call' => ['call'],
            'header' => ['header'],
        ];
    }

    /** Corps d'une méthode : de sa signature à l'accolade fermante de même indentation. */
    private static function methodBody(string $source, string $method): string
    {
        $found = preg_match('/^\tprivate static function ' . $method . '\(.*?^\t}$/ms', $source, $match);
        self::assertSame(1, $found, "méthode $method introuvable");

        return $match[0];
    }

    /** Troisième copie partagée : les méthodes read(), call() et header() de Package. */
    #[DataProvider('sharedMethods')]
    public function testPackageHelperMethodBodiesAreIdenticalInBothUpdaters(string $method): void
    {
        $root = dirname(__DIR__, 2);
        $plugin = self::methodBody((string) file_get_contents("$root/wawp-updater/src/class-package.php"), $method);
        $theme = self::methodBody((string) file_get_contents("$root/wawp-theme-updater/src/class-package.php"), $method);
        self::assertSame($plugin, $theme);
    }

    public function testSameServerAndSameSigningKeys(): void
    {
        self::assertSame(\WAWP\Updater\Config::PUBLIC_KEYS, \WAWP\ThemeUpdater\Config::PUBLIC_KEYS);
        self::assertSame(\WAWP\Updater\Config::ENDPOINT, \WAWP\ThemeUpdater\Config::ENDPOINT);
        self::assertSame(\WAWP\Updater\Config::HOST, \WAWP\ThemeUpdater\Config::HOST);
    }

    /** Le contrôle de Theme URI de check-headers.php doit refuser exactement ce que le client et le serveur refusent. */
    public function testThemeUriPatternOfCheckHeadersMatchesTheClientOne(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/scripts/check-headers.php');
        self::assertSame(1, preg_match("/preg_match\\('([^']+)', \\\$themeUri\\)/", $source, $match), 'motif de Theme URI introuvable dans check-headers.php');
        // Le motif est un littéral PHP entre apostrophes : les doubles barres obliques inverses y valent une seule.
        self::assertSame(\WAWP\ThemeUpdater\Theme_Validator::URL, strtr($match[1], ['\\\\' => '\\']));
    }

    public function testThemeMarkerIsOutsideThePrefixInterceptedByThePluginClient(): void
    {
        self::assertStringStartsNotWith(\WAWP\Updater\Config::ENDPOINT . '/v1/download/', \WAWP\ThemeUpdater\Config::PACKAGE_MARKER);
        self::assertStringStartsWith(\WAWP\ThemeUpdater\Config::ENDPOINT . '/', \WAWP\ThemeUpdater\Config::PACKAGE_MARKER);
    }
}
