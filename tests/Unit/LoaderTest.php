<?php

declare(strict_types=1);

namespace WAWP\ReleaseTools\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class LoaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wawp-loader-' . bin2hex(random_bytes(4));
        $source = dirname(__DIR__, 2) . '/wawp-updater';
        foreach (['ancien' => '1.0.0', 'recent' => '1.2.0'] as $name => $version) {
            mkdir("{$this->dir}/$name", 0777, true);
            $load = (string) file_get_contents("$source/load.php");
            file_put_contents("{$this->dir}/$name/load.php", str_replace("\$wawp_updater_version = '1.0.0';", "\$wawp_updater_version = '$version';", $load));
            file_put_contents("{$this->dir}/$name/bootstrap.php", "<?php\nreturn static function (array \$files): void { echo '$version:' . implode(',', \$files); };\n");
        }
    }

    protected function tearDown(): void
    {
        foreach (['ancien', 'recent'] as $name) {
            @unlink("{$this->dir}/$name/load.php");
            @unlink("{$this->dir}/$name/bootstrap.php");
            @rmdir("{$this->dir}/$name");
        }
        @rmdir($this->dir);
    }

    private function load(array $order): string
    {
        $code = 'define("ABSPATH", "/");'
            . '$GLOBALS["h"] = [];'
            . 'function add_action($n, $c, $p = 10) { $GLOBALS["h"][$n][] = $c; }';
        foreach ($order as $i => $name) {
            $code .= 'require ' . var_export("{$this->dir}/$name/load.php", true) . ';'
                . '\WAWP\Updater\register("/plugins/p' . $i . '/p' . $i . '.php");';
        }
        $code .= 'foreach ($GLOBALS["h"]["plugins_loaded"] as $c) { $c(); }'
            . 'echo "|" . count($GLOBALS["h"]["plugins_loaded"]);';
        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $out;
    }

    public function testHighestVersionWinsWhateverTheOrder(): void
    {
        self::assertSame('1.2.0:/plugins/p0/p0.php,/plugins/p1/p1.php|1', $this->load(['ancien', 'recent']));
        self::assertSame('1.2.0:/plugins/p0/p0.php,/plugins/p1/p1.php|1', $this->load(['recent', 'ancien']));
    }

    public function testVersionConstantMatchesLoader(): void
    {
        $load = (string) file_get_contents(dirname(__DIR__, 2) . '/wawp-updater/load.php');
        self::assertSame(1, preg_match('/\$wawp_updater_version = \'([0-9.]+)\';/', $load, $m));
        self::assertSame(\WAWP\Updater\Config::VERSION, $m[1]);
    }
}
