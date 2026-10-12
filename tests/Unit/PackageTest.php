<?php

declare(strict_types=1);

namespace WAWP\ReleaseTools\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WAWP\Updater\Package;

final class PackageTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wawp-package-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
    }

    /** @param array<string, string> $entries */
    public static function zip(string $path, array $entries): string
    {
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            str_ends_with($name, '/') ? $zip->addEmptyDir(rtrim($name, '/')) : $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }

    public static function main(string $version, string $eol = "\n"): string
    {
        return str_replace("\n", $eol, "<?php\n/**\n * Plugin Name: WAWP Démo\n * Version: $version\n * Update URI: https://updates.wearewp.pro\n */\n");
    }

    /** @return array<string, string> */
    private function valid(string $version = '1.4.0'): array
    {
        return [
            'wawp-demo/' => '',
            'wawp-demo/wawp-demo.php' => self::main($version),
            'wawp-demo/readme.txt' => "Stable tag: $version\n",
            'wawp-demo/wawp-updater/load.php' => '<?php',
        ];
    }

    public function testConformingPackageMatches(): void
    {
        self::assertTrue(Package::matches(self::zip("{$this->dir}/a.zip", $this->valid()), 'wawp-demo', '1.4.0'));
        self::assertTrue(Package::matches(self::zip("{$this->dir}/b.zip", ['wawp-demo/wawp-demo.php' => self::main('1.4.0', "\r\n")]), 'wawp-demo', '1.4.0'), 'fin de ligne CRLF');
    }

    public function testEachMismatchIsRefused(): void
    {
        $cases = [
            'autre version' => [$this->valid('1.3.5'), 'wawp-demo', '1.4.0'],
            'autre slug' => [$this->valid(), 'wawp-autre', '1.4.0'],
            'entrée hors du dossier' => [$this->valid() + ['autre/x.php' => '<?php'], 'wawp-demo', '1.4.0'],
            'préfixe trompeur' => [$this->valid() + ['wawp-demox/x.php' => '<?php'], 'wawp-demo', '1.4.0'],
            'fichier principal absent' => [['wawp-demo/readme.txt' => 'x'], 'wawp-demo', '1.4.0'],
            'en-tête au-delà de 8 Ko' => [['wawp-demo/wawp-demo.php' => "<?php\n" . str_repeat("// remplissage\n", 600) . self::main('1.4.0')], 'wawp-demo', '1.4.0'],
            'slug invalide' => [$this->valid(), '../wawp-demo', '1.4.0'],
            'version vide' => [$this->valid(), 'wawp-demo', ''],
        ];
        $i = 0;
        foreach ($cases as $label => [$entries, $slug, $version]) {
            $zip = self::zip("{$this->dir}/c" . $i++ . '.zip', $entries);
            self::assertFalse(Package::matches($zip, $slug, $version), $label);
        }
    }

    public function testUnreadableArchiveIsRefused(): void
    {
        file_put_contents("{$this->dir}/faux.zip", random_bytes(512));
        self::assertFalse(Package::matches("{$this->dir}/faux.zip", 'wawp-demo', '1.4.0'));
        self::assertFalse(Package::matches("{$this->dir}/absent.zip", 'wawp-demo', '1.4.0'));
    }

    /** Sous-processus sans php.ini (php -n) : ZipArchive indisponible. */
    private function withoutZipArchive(string $abspath, string $zip, string $version): string
    {
        $src = dirname(__DIR__, 2) . '/wawp-updater/src';
        $code = 'define("ABSPATH", ' . var_export($abspath, true) . ');'
            . 'require ' . var_export("$src/class-validator.php", true) . ';'
            . 'require ' . var_export("$src/class-package.php", true) . ';'
            . 'echo class_exists("ZipArchive", false) ? "zip:" : "", var_export(\WAWP\Updater\Package::matches(' . var_export($zip, true) . ', "wawp-demo", ' . var_export($version, true) . '), true);';
        $process = proc_open([PHP_BINARY, '-n', '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $out;
    }

    public function testWithoutZipArchiveNorPclZipThePackageIsRefused(): void
    {
        $zip = self::zip("{$this->dir}/a.zip", $this->valid());
        self::assertSame('false', $this->withoutZipArchive($this->dir . '/', $zip, '1.4.0'));
    }

    public function testPclZipFromCoreIsUsedWhenZipArchiveIsMissing(): void
    {
        $wordpress = dirname(__DIR__) . '/e2e/cache/wordpress-' . json_decode((string) file_get_contents(dirname(__DIR__) . '/e2e/versions.json'), true)['wordpress']['version'] . '.zip';
        if (!is_file($wordpress)) {
            self::markTestSkipped('Archive WordPress absente du cache e2e (lancer composer e2e une fois).');
        }
        $core = new \ZipArchive();
        $core->open($wordpress);
        mkdir("{$this->dir}/wp/wp-admin/includes", 0777, true);
        file_put_contents("{$this->dir}/wp/wp-admin/includes/class-pclzip.php", (string) $core->getFromName('wordpress/wp-admin/includes/class-pclzip.php'));
        $core->close();

        $zip = self::zip("{$this->dir}/a.zip", $this->valid());
        self::assertSame('true', $this->withoutZipArchive("{$this->dir}/wp/", $zip, '1.4.0'));
        self::assertSame('false', $this->withoutZipArchive("{$this->dir}/wp/", $zip, '1.5.0'));
        $foreign = self::zip("{$this->dir}/b.zip", $this->valid() + ['autre/x.php' => '<?php']);
        self::assertSame('false', $this->withoutZipArchive("{$this->dir}/wp/", $foreign, '1.4.0'));
    }
}
