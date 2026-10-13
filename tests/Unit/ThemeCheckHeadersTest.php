<?php

declare(strict_types=1);

namespace WAWP\ReleaseTools\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WAWP\ReleaseTools\Tests\Support\Cli;
use WAWP\ReleaseTools\Tests\Support\Keys;

final class ThemeCheckHeadersTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wawp-check-theme-' . bin2hex(random_bytes(4)) . '/wawp-demo-theme';
        mkdir($this->dir . '/wawp-theme-updater/src', 0777, true);
        $this->write('style.css', self::style());
        $this->write('functions.php', "<?php\nrequire __DIR__ . '/wawp-theme-updater/load.php';\n\\WAWP\\ThemeUpdater\\register( __DIR__ );\n");
        $this->write('.distignore', "/.git\n/tests\n");
        $this->write('wawp-theme-updater/load.php', "<?php\n");
        $this->config([Keys::pair()['public'], Keys::pair()['public']]);
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

    /** @param array<string, string|null> $headers null retire l'en-tête */
    private static function style(array $headers = []): string
    {
        $headers += [
            'Theme Name' => 'WAWP Démo',
            'Version' => '1.4.0',
            'Requires at least' => '6.1',
            'Requires PHP' => '7.4',
            'Text Domain' => 'wawp-demo-theme',
            'Update URI' => 'https://updates.wearewp.pro',
        ];
        $lines = '';
        foreach ($headers as $name => $value) {
            if ($value !== null) {
                $lines .= "$name: $value\n";
            }
        }

        return "/*\n$lines*/\n";
    }

    private function write(string $path, string $content): void
    {
        file_put_contents($this->dir . '/' . $path, $content);
    }

    /** @param list<string> $keys */
    private function config(array $keys, string $version = '1.0.0'): void
    {
        $list = implode(', ', array_map(static fn (string $key): string => "'$key'", $keys));
        $this->write('wawp-theme-updater/src/class-config.php', "<?php\nfinal class Config {\n\tconst VERSION = '$version';\n\tconst PUBLIC_KEYS = array( $list );\n}\n");
    }

    /** @return array{int, string} */
    private function check(string $slug = 'wawp-demo-theme', string $tag = 'v1.4.0', string $min = '1.0.0', string $type = '--type=theme'): array
    {
        [$code, $out, $err] = Cli::run([PHP_BINARY, Cli::php('scripts/check-headers.php'), $type, $this->dir, $slug, $tag, $min]);

        return [$code, $out . $err];
    }

    public function testValidThemePasses(): void
    {
        [$code, $out] = $this->check();
        self::assertSame(0, $code, $out);
        self::assertSame("Contrôles réussis : wawp-demo-theme 1.4.0\n", $out);
    }

    public function testThemeUriIsOptionalButValidatedWhenPresent(): void
    {
        $this->write('style.css', self::style(['Theme URI' => 'https://example.com/theme']));
        [$code, $out] = $this->check();
        self::assertSame(0, $code, $out);
    }

    public function testTypeMayComeLast(): void
    {
        [$code, $out, $err] = Cli::run([PHP_BINARY, Cli::php('scripts/check-headers.php'), $this->dir, 'wawp-demo-theme', 'v1.4.0', '1.0.0', '--type=theme']);
        self::assertSame(0, $code, $out . $err);
    }

    public function testAThemeIsNotAPlugin(): void
    {
        [$code, $out] = $this->check(type: '--type=plugin');
        self::assertSame(1, $code);
        self::assertStringContainsString('Fichier principal wawp-demo-theme.php absent.', $out);
    }

    public function testMinimumThemeUpdaterVersionIsPublished(): void
    {
        self::assertSame(\WAWP\ThemeUpdater\Config::VERSION, trim((string) file_get_contents(dirname(__DIR__, 2) . '/config/min-theme-updater-version.txt')));
    }

    public function testMalformedMinimumVersionOrMissingDirectory(): void
    {
        [$code, $out] = $this->check(min: '1.0');
        self::assertSame(1, $code);
        self::assertStringContainsString('Version minimale de wawp-theme-updater invalide : « 1.0 » (format attendu X.Y.Z, config/min-theme-updater-version.txt).', $out);

        [$code, , $err] = Cli::run([PHP_BINARY, Cli::php('scripts/check-headers.php'), '--type=theme', '', 'wawp-demo-theme', 'v1.4.0', '1.0.0']);
        self::assertSame(1, $code);
        self::assertStringContainsString('Dossier du thème introuvable', $err);
    }

    public function testEachDefectIsReported(): void
    {
        $cases = [
            'style.css absent' => [fn () => unlink($this->dir . '/style.css'), null, 'style.css absent.'],
            'Theme Name' => [fn () => $this->write('style.css', self::style(['Theme Name' => null])), null, 'En-tête Theme Name'],
            'version' => [fn () => $this->write('style.css', self::style(['Version' => '1.3.9'])), null, 'En-tête Version (1.3.9) différent du tag (1.4.0).'],
            'update uri' => [fn () => $this->write('style.css', self::style(['Update URI' => 'https://updates.wearewp.pro/'])), null, 'Update URI'],
            'theme uri invalide' => [fn () => $this->write('style.css', self::style(['Theme URI' => 'ftp://example.com/theme'])), null, 'Theme URI'],
            'theme uri sans hôte' => [fn () => $this->write('style.css', self::style(['Theme URI' => 'not a url'])), null, 'Theme URI'],
            'requires at least trop bas' => [fn () => $this->write('style.css', self::style(['Requires at least' => '6.0'])), null, 'Requires at least'],
            'requires at least absent' => [fn () => $this->write('style.css', self::style(['Requires at least' => null])), null, 'Requires at least'],
            'requires at least sans point (le serveur exige au moins deux nombres)' => [fn () => $this->write('style.css', self::style(['Requires at least' => '7'])), null, 'Requires at least'],
            'requires php sans point (le serveur exige au moins deux nombres)' => [fn () => $this->write('style.css', self::style(['Requires PHP' => '8'])), null, 'En-tête Requires PHP absent ou invalide.'],
            'requires php à quatre points' => [fn () => $this->write('style.css', self::style(['Requires PHP' => '7.4.1.2.3'])), null, 'En-tête Requires PHP absent ou invalide.'],
            'requires php absent' => [fn () => $this->write('style.css', self::style(['Requires PHP' => null])), null, 'Requires PHP'],
            'text domain' => [fn () => $this->write('style.css', self::style(['Text Domain' => 'autre'])), null, 'Text Domain'],
            'thème enfant' => [fn () => $this->write('style.css', self::style(['Template' => 'twentytwentyfive'])), null, 'En-tête Template présent'],
            'fichier de plugin' => [fn () => $this->write('wawp-demo-theme.php', "<?php\n"), null, 'Fichier wawp-demo-theme.php présent à la racine'],
            'functions.php absent' => [fn () => unlink($this->dir . '/functions.php'), null, 'functions.php absent.'],
            'distignore' => [fn () => unlink($this->dir . '/.distignore'), null, 'Fichier .distignore absent.'],
            'bibliothèque absente' => [fn () => unlink($this->dir . '/wawp-theme-updater/src/class-config.php'), null, 'wawp-theme-updater absent ou version illisible'],
            'bibliothèque ancienne' => [fn () => null, ['wawp-demo-theme', 'v1.4.0', '1.1.0'], 'wawp-theme-updater 1.0.0 trop ancien (minimum 1.1.0).'],
            'une seule clé' => [fn () => $this->config([Keys::pair()['public']]), null, 'PUBLIC_KEYS doit contenir exactement deux clés publiques Ed25519 distinctes (wawp-theme-updater/src/class-config.php).'],
            'tag' => [fn () => null, ['wawp-demo-theme', 'v1.4', '1.0.0'], 'Tag invalide'],
            'slug' => [fn () => null, ['Wawp_Demo', 'v1.4.0', '1.0.0'], 'slug du thème'],
        ];
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
