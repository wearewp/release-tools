<?php

declare(strict_types=1);

namespace WAWP\ReleaseTools\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WAWP\ReleaseTools\Tests\Support\Cli;
use WAWP\ReleaseTools\Tests\Support\Keys;

final class CheckPackageTest extends TestCase
{
    private const CLASSES = ['config', 'validator', 'signature', 'client', 'updater', 'package'];

    private string $dir;
    /** @var array{string, string} */
    private array $keys;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wawp-pkg-' . bin2hex(random_bytes(4)) . '/build/wawp-demo';
        mkdir($this->dir . '/wawp-updater/src', 0777, true);
        $this->keys = [Keys::pair()['public'], Keys::pair()['public']];
        $this->write('wawp-demo.php', self::main());
        $this->write('readme.txt', "Stable tag: 1.4.0\n");
        $this->write('wawp-updater/load.php', "<?php\n\$wawp_updater_version = '1.0.0';\n");
        $this->write('wawp-updater/bootstrap.php', "<?php\n");
        foreach (self::CLASSES as $class) {
            $this->write("wawp-updater/src/class-$class.php", "<?php\n");
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

    private static function main(string $version = '1.4.0', string $uri = 'https://updates.wearewp.pro', string $load = "require __DIR__ . '/wawp-updater/load.php';", string $register = '\WAWP\Updater\register( __FILE__ );'): string
    {
        return "<?php\n/**\n * Plugin Name: WAWP Démo\n * Version: $version\n * Update URI: $uri\n */\n\ndefined( 'ABSPATH' ) || exit;\n\n$load\n$register\n";
    }

    private function write(string $path, string $content): void
    {
        file_put_contents($this->dir . '/' . $path, $content);
    }

    /** @param list<string> $keys */
    private function config(array $keys): void
    {
        $list = implode(', ', array_map(static fn (string $key): string => "'$key'", $keys));
        $this->write('wawp-updater/src/class-config.php', "<?php\nfinal class Config {\n\tconst PUBLIC_KEYS = array( $list );\n}\n");
    }

    /** @return array{int, string} */
    private function check(?string $dir = null, string $slug = 'wawp-demo', string $version = '1.4.0'): array
    {
        [$code, $out, $err] = Cli::run([PHP_BINARY, Cli::php('scripts/check-package.php'), $dir ?? $this->dir, $slug, $version]);

        return [$code, $out . $err];
    }

    public function testValidPackagePasses(): void
    {
        [$code, $out] = $this->check();
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('Paquet conforme : wawp-demo 1.4.0', $out);
    }

    public function testEachDefectIsReported(): void
    {
        $cases = [
            'load.php absent' => [fn () => unlink($this->dir . '/wawp-updater/load.php'), null, 'wawp-updater/load.php'],
            'bootstrap.php absent' => [fn () => unlink($this->dir . '/wawp-updater/bootstrap.php'), null, 'wawp-updater/bootstrap.php'],
            'fichier principal absent' => [fn () => unlink($this->dir . '/wawp-demo.php'), null, 'wawp-demo.php absent'],
            'chargement absent' => [fn () => $this->write('wawp-demo.php', self::main(load: '')), null, 'wawp-updater/load.php'],
            'inscription absente' => [fn () => $this->write('wawp-demo.php', self::main(register: '')), null, 'register('],
            'version' => [fn () => $this->write('wawp-demo.php', self::main(version: '1.3.9')), null, 'En-tête Version'],
            'update uri différent' => [fn () => $this->write('wawp-demo.php', self::main(uri: 'https://updates.wearewp.pro/')), null, 'Update URI'],
            'une seule clé' => [fn () => $this->config([$this->keys[0]]), null, 'PUBLIC_KEYS'],
            'clés identiques' => [fn () => $this->config([$this->keys[0], $this->keys[0]]), null, 'PUBLIC_KEYS'],
            'clé invalide' => [fn () => $this->config([$this->keys[0], base64_encode('trop court')]), null, 'PUBLIC_KEYS'],
            'dossier .git' => [fn () => mkdir($this->dir . '/vendor/lib/.git', 0777, true), null, '.git'],
            'slug invalide' => [fn () => null, [null, 'Wawp_Demo', '1.4.0'], 'Slug invalide'],
            'version invalide' => [fn () => null, [null, 'wawp-demo', '1.4'], 'Version invalide'],
            'dossier vide' => [fn () => null, ['', 'wawp-demo', '1.4.0'], 'Dossier'],
        ];
        foreach (self::CLASSES as $class) {
            $cases["class-$class.php absent"] = [fn () => unlink($this->dir . "/wawp-updater/src/class-$class.php"), null, "src/class-$class.php"];
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
