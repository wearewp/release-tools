<?php

declare(strict_types=1);

namespace WAWP\ReleaseTools\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WAWP\ThemeUpdater\Package;

final class ThemePackageTest extends TestCase
{
    private const SLUG = 'wawp-demo-theme';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wawp-theme-package-' . bin2hex(random_bytes(4));
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

    public static function style(string $version, string $slug = self::SLUG, string $eol = "\n"): string
    {
        return str_replace("\n", $eol, "/*\nTheme Name: WAWP Démo\nVersion: $version\nRequires at least: 6.1\nRequires PHP: 7.4\nText Domain: $slug\nUpdate URI: https://updates.wearewp.pro\n*/\n");
    }

    /** @return array<string, string> */
    public static function entries(string $slug, string $version): array
    {
        return [
            "$slug/" => '',
            "$slug/style.css" => self::style($version, $slug),
            "$slug/functions.php" => "<?php\n",
            "$slug/templates/index.html" => "<!-- wp:post-content /-->\n",
            "$slug/wawp-theme-updater/load.php" => '<?php',
        ];
    }

    public function testConformingPackageMatches(): void
    {
        self::assertTrue(Package::matches(PackageTest::zip("{$this->dir}/a.zip", self::entries(self::SLUG, '1.4.0')), self::SLUG, '1.4.0'));
        self::assertTrue(Package::matches(PackageTest::zip("{$this->dir}/b.zip", [self::SLUG . '/style.css' => self::style('1.4.0', self::SLUG, "\r\n")]), self::SLUG, '1.4.0'), 'fin de ligne CRLF');
    }

    public function testEachMismatchIsRefused(): void
    {
        $valid = self::entries(self::SLUG, '1.4.0');
        $cases = [
            'autre version' => [self::entries(self::SLUG, '1.3.5'), self::SLUG, '1.4.0'],
            'autre slug' => [$valid, 'theme-autre', '1.4.0'],
            'entrée hors du dossier' => [$valid + ['autre/x.php' => '<?php'], self::SLUG, '1.4.0'],
            'préfixe trompeur' => [$valid + ['wawp-demo-themex/x.php' => '<?php'], self::SLUG, '1.4.0'],
            'segment ..' => [$valid + ['wawp-demo-theme/../x.php' => '<?php'], self::SLUG, '1.4.0'],
            'segment .' => [$valid + ['wawp-demo-theme/./x.php' => '<?php'], self::SLUG, '1.4.0'],
            'entrée avec antislash' => [$valid + ['wawp-demo-theme\\x.php' => '<?php'], self::SLUG, '1.4.0'],
            'antislash après le dossier du thème' => [$valid + ['wawp-demo-theme/sub\\x.php' => '<?php'], self::SLUG, '1.4.0'],
            'style.css imbriqué dont la version diffère du style.css racine' => [[self::SLUG . '/style.css' => self::style('1.3.5'), self::SLUG . '/sub/style.css' => self::style('1.4.0')], self::SLUG, '1.4.0'],
            'casse en double' => [$valid + ['wawp-demo-theme/Functions.php' => '<?php'], self::SLUG, '1.4.0'],
            'style.css absent' => [[self::SLUG . '/functions.php' => '<?php'], self::SLUG, '1.4.0'],
            'style.css sans Version' => [[self::SLUG . '/style.css' => "body { color: red; }\n"], self::SLUG, '1.4.0'],
            'en-tête au-delà de 8 Ko' => [[self::SLUG . '/style.css' => '/*' . str_repeat("remplissage\n", 700) . '*/' . self::style('1.4.0')], self::SLUG, '1.4.0'],
            'slug invalide' => [$valid, '../wawp-demo-theme', '1.4.0'],
            'version vide' => [$valid, self::SLUG, ''],
        ];
        $i = 0;
        foreach ($cases as $label => [$entries, $slug, $version]) {
            $zip = PackageTest::zip("{$this->dir}/c" . $i++ . '.zip', $entries);
            self::assertFalse(Package::matches($zip, $slug, $version), $label);
        }
    }

    public function testDuplicateEntryIsRefused(): void
    {
        // Deux entrées « style.css » : à l'extraction, la seconde (9.9.9) écraserait celle qui a été contrôlée.
        $zip = PackageTest::zip("{$this->dir}/d.zip", self::entries(self::SLUG, '1.4.0') + [self::SLUG . '/style.csz' => self::style('9.9.9')]);
        file_put_contents($zip, str_replace(self::SLUG . '/style.csz', self::SLUG . '/style.css', (string) file_get_contents($zip)));
        $archive = new \ZipArchive();
        self::assertTrue($archive->open($zip), 'archive toujours lisible');
        $archive->close();

        self::assertFalse(Package::matches($zip, self::SLUG, '1.4.0'));
    }

    public function testUnreadableArchiveIsRefused(): void
    {
        file_put_contents("{$this->dir}/faux.zip", random_bytes(512));
        self::assertFalse(Package::matches("{$this->dir}/faux.zip", self::SLUG, '1.4.0'));
        self::assertFalse(Package::matches("{$this->dir}/absent.zip", self::SLUG, '1.4.0'));
    }

    /** Sous-processus sans php.ini (php -n) : ZipArchive indisponible. */
    private function withoutZipArchive(string $abspath, string $zip, string $version): string
    {
        $src = dirname(__DIR__, 2) . '/wawp-theme-updater/src';
        $code = 'define("ABSPATH", ' . var_export($abspath, true) . ');'
            . 'require ' . var_export("$src/class-config.php", true) . ';'
            . 'require ' . var_export("$src/class-validator.php", true) . ';'
            . 'require ' . var_export("$src/class-package.php", true) . ';'
            . 'echo class_exists("ZipArchive", false) ? "zip:" : "", var_export(\WAWP\ThemeUpdater\Package::matches(' . var_export($zip, true) . ', "wawp-demo-theme", ' . var_export($version, true) . '), true);';
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
        $zip = PackageTest::zip("{$this->dir}/a.zip", self::entries(self::SLUG, '1.4.0'));
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

        $zip = PackageTest::zip("{$this->dir}/a.zip", self::entries(self::SLUG, '1.4.0'));
        self::assertSame('true', $this->withoutZipArchive("{$this->dir}/wp/", $zip, '1.4.0'));
        self::assertSame('false', $this->withoutZipArchive("{$this->dir}/wp/", $zip, '1.5.0'));
        $foreign = PackageTest::zip("{$this->dir}/b.zip", self::entries(self::SLUG, '1.4.0') + ['autre/x.php' => '<?php']);
        self::assertSame('false', $this->withoutZipArchive("{$this->dir}/wp/", $foreign, '1.4.0'));
    }
}
