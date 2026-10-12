<?php

declare(strict_types=1);

namespace WAWP\ReleaseTools\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WAWP\ReleaseTools\Tests\Support\Cli;

final class WorkflowPolicyTest extends TestCase
{
    private static function file(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $path);
    }

    /** @return array<string, string> blocs de jobs indexés par nom */
    private static function jobs(string $yaml): array
    {
        $parts = preg_split('/^  ([a-z-]+):\n/m', (string) substr($yaml, (int) strpos($yaml, "\njobs:\n")), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $jobs = [];
        for ($i = 1; $i + 1 < count($parts); $i += 2) {
            $jobs[$parts[$i]] = $parts[$i + 1];
        }

        return $jobs;
    }

    public function testEveryActionIsOfficialAndPinnedBySha(): void
    {
        foreach (['.github/workflows/plugin-release.yml', 'templates/release.yml'] as $path) {
            preg_match_all('/^\s*(?:-\s*)?uses:\s*(\S+)/m', self::file($path), $m);
            foreach ($m[1] as $uses) {
                if (str_starts_with($uses, 'wearewp/release-tools/')) {
                    continue;
                }
                self::assertMatchesRegularExpression('#^actions/[a-z-]+@[0-9a-f]{40}$#', $uses, "$path : $uses");
            }
        }
    }

    public function testJobSeparationAndPermissions(): void
    {
        $yaml = self::file('.github/workflows/plugin-release.yml');
        self::assertStringContainsString("permissions:\n  contents: read\n", $yaml);
        $jobs = self::jobs($yaml);
        self::assertSame(['build', 'sign-upload', 'publish-release'], array_keys($jobs));

        self::assertStringNotContainsString('secrets.', $jobs['build'], 'aucun secret dans la construction');
        self::assertStringContainsString("if: startsWith(github.ref, 'refs/tags/v')", $jobs['build']);

        self::assertStringContainsString('secrets.SIGNING_KEY', $jobs['sign-upload']);
        self::assertStringNotContainsString('path: plugin', $jobs['sign-upload'], 'aucun code du plugin dans le job à secrets');
        self::assertStringNotContainsString('composer', $jobs['sign-upload']);
        self::assertStringNotContainsString('npm', $jobs['sign-upload']);
        self::assertStringContainsString("contents: read", $jobs['sign-upload']);

        self::assertStringContainsString("contents: write", $jobs['publish-release']);
        self::assertStringNotContainsString('secrets.SIGNING_KEY', $jobs['publish-release']);
        self::assertStringNotContainsString('secrets.FTP', $jobs['publish-release']);

        foreach ($jobs as $name => $job) {
            self::assertStringContainsString('timeout-minutes:', $job, $name);
            self::assertSame(
                substr_count($job, 'actions/checkout@'),
                substr_count($job, 'persist-credentials: false'),
                "$name : chaque checkout doit désactiver la conservation des identifiants"
            );
        }
        self::assertStringNotContainsString('actions/checkout@', $jobs['publish-release'], 'aucun clone dans le job qui écrit');
    }

    public function testCallerTemplate(): void
    {
        $yaml = self::file('templates/release.yml');
        self::assertStringContainsString("tags: ['v*.*.*']", $yaml);
        self::assertStringContainsString('permissions: {}', $yaml);
        self::assertStringContainsString('uses: wearewp/release-tools/.github/workflows/plugin-release.yml@v1', $yaml);
        self::assertStringContainsString('secrets: inherit', $yaml);
    }

    public function testUploadScriptForcesTlsAndUsesPartFiles(): void
    {
        $script = self::file('scripts/upload-ftps.sh');
        foreach (['set ftp:ssl-force true', 'set ftp:ssl-protect-data true', 'set ssl:verify-certificate true', '--env-password', '.part'] as $needle) {
            self::assertStringContainsString($needle, $script);
        }
        self::assertLessThan((int) strpos($script, '$name.sig.part'), (int) strpos($script, '"$name.part"'), 'zip avant signature');
        [$code, , $err] = Cli::run(['bash', '-n', 'scripts/upload-ftps.sh']);
        self::assertSame(0, $code, $err);
    }
}
