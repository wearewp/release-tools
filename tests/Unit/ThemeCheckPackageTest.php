<?php

declare(strict_types=1);

namespace WAWP\ReleaseTools\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WAWP\ReleaseTools\Tests\Support\Cli;
use WAWP\ReleaseTools\Tests\Support\Keys;

final class ThemeCheckPackageTest extends TestCase
{
    private const CLASSES = ['config', 'validator', 'theme-validator', 'signature', 'client', 'package', 'updater'];

    private string $dir;
    /** @var array{string, string} */
    private array $keys;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wawp-theme-pkg-' . bin2hex(random_bytes(4)) . '/build/wawp-demo-theme';
        mkdir($this->dir . '/wawp-theme-updater/src', 0777, true);
        $this->keys = [Keys::pair()['public'], Keys::pair()['public']];
        $this->write('style.css', self::style());
        $this->write('functions.php', self::functions());
        $this->write('wawp-theme-updater/load.php', "<?php\n");
        $this->write('wawp-theme-updater/bootstrap.php', "<?php\n");
        foreach (self::CLASSES as $class) {
            $this->write("wawp-theme-updater/src/class-$class.php", "<?php\n");
        }
        $this->config($this->keys);
    }

    protected function tearDown(): void
    {
        $root = dirname($this->dir, 2);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($root);
    }

    private static function style(string $version = '1.4.0', string $uri = 'https://updates.wearewp.pro'): string
    {
        return "/*\nTheme Name: WAWP Démo\nVersion: $version\nRequires at least: 6.1\nRequires PHP: 7.4\nText Domain: wawp-demo-theme\nUpdate URI: $uri\n*/\n";
    }

    private static function functions(string $load = "require __DIR__ . '/wawp-theme-updater/load.php';", string $register = '\WAWP\ThemeUpdater\register( __DIR__ );'): string
    {
        return "<?php\ndefined( 'ABSPATH' ) || exit;\n\n$load\n$register\n";
    }

    private function write(string $path, string $content): void
    {
        file_put_contents($this->dir . '/' . $path, $content);
    }

    /** @param list<string> $keys */
    private function config(array $keys): void
    {
        $list = implode(', ', array_map(static fn (string $key): string => "'$key'", $keys));
        $this->write('wawp-theme-updater/src/class-config.php', "<?php\nfinal class Config {\n\tconst VERSION = '1.0.0';\n\tconst PUBLIC_KEYS = array( $list );\n}\n");
    }

    /** @return array{int, string} */
    private function check(?string $dir = null, string $slug = 'wawp-demo-theme', string $version = '1.4.0', string $type = '--type=theme'): array
    {
        [$code, $out, $err] = Cli::run([PHP_BINARY, Cli::php('scripts/check-package.php'), $type, $dir ?? $this->dir, $slug, $version]);

        return [$code, $out . $err];
    }

    public function testValidThemePackagePasses(): void
    {
        [$code, $out] = $this->check();
        self::assertSame(0, $code, $out);
        self::assertSame("Paquet conforme : wawp-demo-theme 1.4.0\n", $out);
    }

    public function testAThemePackageIsNotAPluginPackage(): void
    {
        [$code, $out] = $this->check(type: '--type=plugin');
        self::assertSame(1, $code);
        self::assertStringContainsString('Fichier principal wawp-demo-theme.php absent du paquet.', $out);
    }

    public function testEachDefectIsReported(): void
    {
        $cases = [
            'load.php absent' => [fn () => unlink($this->dir . '/wawp-theme-updater/load.php'), null, 'wawp-theme-updater/load.php absent du paquet.'],
            'bootstrap.php absent' => [fn () => unlink($this->dir . '/wawp-theme-updater/bootstrap.php'), null, 'wawp-theme-updater/bootstrap.php absent du paquet.'],
            'functions.php absent' => [fn () => unlink($this->dir . '/functions.php'), null, 'functions.php absent du paquet.'],
            'chargement absent' => [fn () => $this->write('functions.php', self::functions(load: '')), null, 'functions.php ne charge pas wawp-theme-updater/load.php.'],
            'inscription absente' => [fn () => $this->write('functions.php', self::functions(register: '')), null, 'functions.php n\'appelle pas \\WAWP\\ThemeUpdater\\register(.'],
            'style.css absent' => [fn () => unlink($this->dir . '/style.css'), null, 'style.css absent du paquet.'],
            'version' => [fn () => $this->write('style.css', self::style(version: '1.3.9')), null, 'En-tête Version de style.css (1.3.9) différent de la version publiée (1.4.0).'],
            'update uri' => [fn () => $this->write('style.css', self::style(uri: 'https://updates.wearewp.pro/')), null, 'Update URI'],
            'fichier de plugin' => [fn () => $this->write('wawp-demo-theme.php', "<?php\n"), null, 'wawp-demo-theme.php présent'],
            'une seule clé' => [fn () => $this->config([$this->keys[0]]), null, 'PUBLIC_KEYS doit contenir exactement deux clés publiques Ed25519 distinctes (wawp-theme-updater/src/class-config.php).'],
            'clés identiques' => [fn () => $this->config([$this->keys[0], $this->keys[0]]), null, 'PUBLIC_KEYS'],
            'dossier .git' => [fn () => mkdir($this->dir . '/vendor/lib/.git', 0777, true), null, '.git'],
            'slug invalide' => [fn () => null, [null, 'Wawp_Demo', '1.4.0'], 'Slug invalide'],
            'version invalide' => [fn () => null, [null, 'wawp-demo-theme', '1.4'], 'Version invalide'],
            'dossier vide' => [fn () => null, ['', 'wawp-demo-theme', '1.4.0'], 'Dossier'],
        ];
        foreach (self::CLASSES as $class) {
            $cases["class-$class.php absent"] = [fn () => unlink($this->dir . "/wawp-theme-updater/src/class-$class.php"), null, "wawp-theme-updater/src/class-$class.php absent du paquet."];
        }
        foreach ($cases as $label => [$mutate, $args, $expected]) {
            $this->tearDown();
            $this->setUp();
            $mutate();
            [$code, $out] = $args === null ? $this->check() : $this->check(...$args);
            self::assertSame(1, $code, "$label : $out");
            self::assertStringContainsString($expected, $out, $label);
        }
    }
}
