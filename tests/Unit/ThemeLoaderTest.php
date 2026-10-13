<?php

declare(strict_types=1);

namespace WAWP\ReleaseTools\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * load.php réel, dans un sous-processus, avec un bootstrap.php factice qui affiche ses arguments.
 */
final class ThemeLoaderTest extends TestCase
{
    private string $dir;
    private string $theme;
    private string $parent;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wawp-theme-loader-' . bin2hex(random_bytes(4));
        $this->theme = "{$this->dir}/themes/wawp-demo-theme";
        $this->parent = "{$this->dir}/themes/theme-parent";
        foreach ([$this->theme, $this->parent] as $root) {
            mkdir("$root/wawp-theme-updater", 0777, true);
            copy(dirname(__DIR__, 2) . '/wawp-theme-updater/load.php', "$root/wawp-theme-updater/load.php");
            file_put_contents("$root/wawp-theme-updater/bootstrap.php", "<?php\nreturn static function (string \$stylesheet, string \$dir): void { echo 'start:' . \$stylesheet . ':' . basename(\$dir) . ';'; };\n");
        }
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
    }

    private function runPhp(string $calls): string
    {
        $code = 'define("ABSPATH", "/");'
            . 'function get_stylesheet() { return "wawp-demo-theme"; }'
            . 'function get_stylesheet_directory() { return ' . var_export($this->theme, true) . '; }'
            . $calls;
        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $out;
    }

    private static function load(string $root): string
    {
        return 'require ' . var_export("$root/wawp-theme-updater/load.php", true) . ';';
    }

    private static function register(mixed $dir): string
    {
        return '\WAWP\ThemeUpdater\register(' . var_export($dir, true) . ');';
    }

    public function testActiveThemeStartsTheClientOnce(): void
    {
        $out = $this->runPhp(self::load($this->theme) . self::register($this->theme) . self::register($this->theme . '/'));
        self::assertSame('start:wawp-demo-theme:wawp-demo-theme;', $out);
    }

    public function testParentThemeForeignDirectoryOrBadArgumentIsIgnored(): void
    {
        $out = $this->runPhp(
            self::load($this->parent) . self::load($this->theme)
            . self::register($this->parent)
            . self::register($this->dir . '/absent')
            . self::register(42)
            . self::register(null)
            . 'echo "fin";'
        );
        self::assertSame('fin', $out, 'deux copies chargées sans « Cannot redeclare », aucun démarrage');
    }

    public function testMissingBootstrapIsSilent(): void
    {
        unlink("{$this->theme}/wawp-theme-updater/bootstrap.php");
        self::assertSame('fin', $this->runPhp(self::load($this->theme) . self::register($this->theme) . 'echo "fin";'));
    }

    public function testThrowingBootstrapNeverBreaksTheSite(): void
    {
        file_put_contents("{$this->theme}/wawp-theme-updater/bootstrap.php", "<?php\nthrow new \\RuntimeException('panne');\n");
        self::assertSame('fin', $this->runPhp(self::load($this->theme) . self::register($this->theme) . 'echo "fin";'));
    }

    public function testLoaderLeavesNoVariableInGlobalScope(): void
    {
        $out = $this->runPhp(
            self::load($this->theme) . self::register($this->theme)
            . 'echo count(array_filter(array_keys(get_defined_vars()), static fn ($name) => str_starts_with((string) $name, "wawp")));'
        );
        self::assertSame('start:wawp-demo-theme:wawp-demo-theme;0', $out, 'chargement et register() ne laissent aucune variable globale');
    }

    public function testEmptyDirectoryIsIgnored(): void
    {
        $cwd = (string) getcwd();
        chdir($this->theme);
        try {
            $out = $this->runPhp(self::load($this->theme) . self::register('') . 'echo "fin";');
        } finally {
            chdir($cwd);
        }
        self::assertSame('fin', $out, "'' ne doit pas se résoudre au dossier courant");
    }
}
