<?php

declare(strict_types=1);

namespace WAWP\ReleaseTools\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WAWP\ReleaseTools\Tests\Support\Cli;
use WAWP\ReleaseTools\Tests\Support\Keys;

final class CheckHeadersTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wawp-check-' . bin2hex(random_bytes(4)) . '/wawp-demo';
        mkdir($this->dir . '/wawp-updater/src', 0777, true);
        $keys = [Keys::pair()['public'], Keys::pair()['public']];
        $this->write('wawp-demo.php', "<?php\n/**\n * Plugin Name: WAWP Démo\n * Version: 1.4.0\n * Update URI: https://updates.wearewp.pro\n */\n");
        $this->write('readme.txt', "=== WAWP Démo ===\nStable tag: 1.4.0\n");
        $this->write('.distignore', "/.git\n/tests\n");
        $this->write('wawp-updater/load.php', "<?php\n\$wawp_updater_version = '1.0.0';\n");
        $this->write('wawp-updater/src/class-config.php', "<?php\nfinal class Config {\n\tconst PUBLIC_KEYS = array( '{$keys[0]}', '{$keys[1]}' );\n}\n");
    }

    protected function tearDown(): void
    {
        $root = dirname($this->dir);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($root);
    }

    private function write(string $path, string $content): void
    {
        file_put_contents($this->dir . '/' . $path, $content);
    }

    /** @return array{int, string} */
    private function check(string $slug = 'wawp-demo', string $tag = 'v1.4.0', string $min = '1.0.0'): array
    {
        [$code, $out, $err] = Cli::run([PHP_BINARY, Cli::php('scripts/check-headers.php'), $this->dir, $slug, $tag, $min]);

        return [$code, $out . $err];
    }

    public function testMalformedMinimumUpdaterVersionOrEmptyDirectoryIsRefused(): void
    {
        foreach (['', '1.0', 'v1.0.0', '1.0.0-beta', "1.0.0\n2.0.0"] as $min) {
            [$code, $out] = $this->check('wawp-demo', 'v1.4.0', $min);
            self::assertSame(1, $code, var_export($min, true));
            self::assertStringContainsString('Version minimale de wawp-updater invalide', $out, var_export($min, true));
        }
        [$code, $out, $err] = Cli::run([PHP_BINARY, Cli::php('scripts/check-headers.php'), '', 'wawp-demo', 'v1.4.0', '1.0.0']);
        self::assertSame(1, $code);
        self::assertStringContainsString('Dossier du plugin', $out . $err);
    }

    public function testValidPluginPasses(): void
    {
        [$code, $out] = $this->check();
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('Contrôles réussis : wawp-demo 1.4.0', $out);
    }

    public function testEachDefectIsReported(): void
    {
        $cases = [
            'tag' => [fn () => null, ['wawp-demo', 'v1.4', '1.0.0'], 'Tag invalide'],
            'slug' => [fn () => null, ['Wawp_Demo', 'v1.4.0', '1.0.0'], 'Slug invalide'],
            'version' => [fn () => $this->write('wawp-demo.php', "<?php\n/* Plugin Name: X\n * Version: 1.3.9\n * Update URI: https://updates.wearewp.pro\n */\n"), null, 'En-tête Version'],
            'update uri' => [fn () => $this->write('wawp-demo.php', "<?php\n/* Plugin Name: X\n * Version: 1.4.0\n */\n"), null, 'Update URI'],
            'stable tag' => [fn () => $this->write('readme.txt', "Stable tag: 1.4.1\n"), null, 'Stable tag'],
            'distignore' => [fn () => unlink($this->dir . '/.distignore'), null, '.distignore'],
            'updater ancien' => [fn () => null, ['wawp-demo', 'v1.4.0', '1.1.0'], 'wawp-updater'],
            'clés vides' => [fn () => $this->write('wawp-updater/src/class-config.php', "<?php\nconst PUBLIC_KEYS = array();\n"), null, 'PUBLIC_KEYS'],
            'puc' => [fn () => mkdir($this->dir . '/plugin-update-checker'), null, 'Plugin Update Checker'],
            'git updater' => [fn () => $this->write('wawp-demo.php', "<?php\n/* Plugin Name: X\n * Version: 1.4.0\n * Update URI: https://updates.wearewp.pro\n * GitHub Plugin URI: wearewp/wawp-demo\n */\n"), null, 'GitHub Plugin URI'],
        ];
        foreach ($cases as $label => [$mutate, $args, $expected]) {
            $this->tearDown();
            $this->setUp();
            $mutate();
            [$code, $out] = $args === null ? $this->check() : $this->check(...$args);
            self::assertSame(1, $code, $label);
            self::assertStringContainsString($expected, $out, $label);
        }
    }
}
