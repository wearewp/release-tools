<?php

declare(strict_types=1);

namespace WAWP\ReleaseTools\Tests\Support;

final class Cli
{
    /**
     * @param list<string>          $command
     * @param array<string, string> $env
     * @return array{int, string, string}
     */
    public static function run(array $command, array $env = []): array
    {
        $environment = array_merge(getenv(), $env);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
        if (!is_resource($process)) {
            throw new \RuntimeException('Lancement impossible');
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }

    public static function php(string $script): string
    {
        return dirname(__DIR__, 2) . '/' . $script;
    }
}
