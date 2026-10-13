<?php

declare(strict_types=1);

namespace WAWP\ReleaseTools\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * bootstrap.php réel, dans un sous-processus : un fichier de src/ absent (quarantaine antivirus
 * d'hébergeur) ne doit jamais provoquer d'erreur fatale.
 */
final class ThemeBootstrapTest extends TestCase
{
    private const CLASSES = ['config', 'validator', 'theme-validator', 'signature', 'client', 'package', 'updater'];

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wawp-theme-bootstrap-' . bin2hex(random_bytes(4));
        $source = dirname(__DIR__, 2) . '/wawp-theme-updater';
        mkdir("{$this->dir}/src", 0777, true);
        copy("$source/bootstrap.php", "{$this->dir}/bootstrap.php");
        foreach (self::CLASSES as $class) {
            copy("$source/src/class-$class.php", "{$this->dir}/src/class-$class.php");
        }
    }

    protected function tearDown(): void
    {
        foreach (glob("{$this->dir}/src/*") ?: [] as $file) {
            unlink($file);
        }
        @unlink("{$this->dir}/bootstrap.php");
        @rmdir("{$this->dir}/src");
        @rmdir($this->dir);
    }

    private function start(): string
    {
        $code = 'require ' . var_export(dirname(__DIR__) . '/wp-stubs.php', true) . ';'
            . '$start = require ' . var_export("{$this->dir}/bootstrap.php", true) . ';'
            . '$start("wawp-demo-theme", "/var/www/wp-content/themes/wawp-demo-theme");'
            . 'echo "hooks:" . count(WawpTestState::$hooks);';
        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $out;
    }

    public function testCompleteCopyRegistersHooks(): void
    {
        self::assertSame('hooks:6', $this->start());
    }

    public function testMissingSourceFileRegistersNothingAndNeverFails(): void
    {
        foreach (self::CLASSES as $class) {
            $this->tearDown();
            $this->setUp();
            unlink("{$this->dir}/src/class-$class.php");
            self::assertSame('hooks:0', $this->start(), "class-$class.php absent");
        }
    }
}
