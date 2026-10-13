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
        foreach (['.github/workflows/plugin-release.yml', 'templates/release.yml', '.github/workflows/theme-release.yml', 'templates/theme-release.yml'] as $path) {
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

    /** @return array<string, string> étapes d'un job indexées par nom */
    private static function steps(string $job): array
    {
        $parts = preg_split('/^      - name: (.+)\n/m', $job, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $steps = [];
        for ($i = 1; $i + 1 < count($parts); $i += 2) {
            $steps[$parts[$i]] = $parts[$i + 1];
        }

        return $steps;
    }

    public function testBuildChecksThePackageAndTakesTheHighestMinimumUpdaterVersion(): void
    {
        $build = self::jobs(self::file('.github/workflows/plugin-release.yml'))['build'];
        $steps = self::steps($build);

        $main = $steps['Version minimale de wawp-updater exigée sur main'] ?? '';
        foreach (['uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1', 'repository: wearewp/release-tools', 'ref: main', 'path: tools-main', 'sparse-checkout: config', 'persist-credentials: false'] as $needle) {
            self::assertStringContainsString($needle, $main, $needle);
        }
        $checks = $steps['Contrôles avant publication'] ?? '';
        self::assertStringContainsString('tools/config/min-updater-version.txt', $checks);
        self::assertStringContainsString('tools-main/config/min-updater-version.txt', $checks);
        self::assertStringContainsString('sort -V | tail -n 1', $checks);
        self::assertStringContainsString('"$min"', $checks);

        $package = $steps['Paquet'] ?? '';
        $rsync = (int) strpos($package, 'rsync ');
        $check = (int) strpos($package, 'php tools/scripts/check-package.php "build/$SLUG" "$SLUG" "$VERSION"');
        $zip = (int) strpos($package, 'zip -rqX');
        self::assertGreaterThan(0, $check, 'contrôle du paquet construit');
        self::assertLessThan($check, $rsync);
        self::assertLessThan($zip, $check);
    }

    public function testLftpIsInstalledInAStepWithoutSecrets(): void
    {
        $sign = self::jobs(self::file('.github/workflows/plugin-release.yml'))['sign-upload'];
        $steps = self::steps($sign);
        self::assertArrayHasKey('Installation de lftp', $steps);
        self::assertStringNotContainsString('env:', $steps['Installation de lftp']);
        self::assertStringContainsString('apt-get install', $steps['Installation de lftp']);
        $names = array_keys($steps);
        self::assertLessThan(array_search('Signature', $names, true), array_search('Installation de lftp', $names, true));
        foreach ($steps as $name => $step) {
            if (str_contains($step, 'secrets.')) {
                self::assertStringNotContainsString('apt-get', $step, $name);
            }
        }
    }

    public function testArtifactsAreOverwrittenAndReleaseAssetsNeverClobbered(): void
    {
        $yaml = self::file('.github/workflows/plugin-release.yml');
        self::assertSame(substr_count($yaml, 'actions/upload-artifact@'), substr_count($yaml, 'overwrite: true'));
        self::assertSame(2, substr_count($yaml, 'overwrite: true'));
        self::assertStringNotContainsString('--clobber', $yaml);
        $release = self::steps(self::jobs($yaml)['publish-release'])['Release'] ?? '';
        self::assertStringContainsString('--json assets', $release);
        self::assertStringContainsString('contient déjà', $release);
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
        self::assertStringContainsString('lftp -c "set cmd:fail-exit true;', $script, 'lftp s\'arrête à la première commande en échec');
        [$code, , $err] = Cli::run(['bash', '-n', 'scripts/upload-ftps.sh']);
        self::assertSame(0, $code, $err);
    }

    public function testUploadScriptValidatesThePackageNameBeforeLftp(): void
    {
        $script = self::file('scripts/upload-ftps.sh');
        self::assertLessThan((int) strpos($script, 'lftp -c'), (int) strpos($script, 'Nom de paquet invalide'));
        foreach (['dist/Wawp-Demo-1.4.0.zip', 'dist/wawp-demo-1.4.zip', 'dist/wawp-demo-1.4.0.1.zip', 'dist/wawp demo-1.4.0.zip', 'dist/wawp-demo-1.4.0.zip;rm', 'dist/wawp-demo-123456.0.0.zip', ''] as $zip) {
            [$code, , $err] = Cli::run(['bash', 'scripts/upload-ftps.sh', $zip]);
            self::assertSame(1, $code, $zip);
            self::assertStringContainsString('Nom de paquet invalide', $err, $zip);
        }
        // Nom valide : le contrôle suivant (variables, ou paquet absent) prend le relais.
        [$code, , $err] = Cli::run(['bash', 'scripts/upload-ftps.sh', 'dist/wawp-demo-1.4.0.zip']);
        self::assertSame(1, $code);
        self::assertStringNotContainsString('Nom de paquet invalide', $err);
    }

    public function testThemeWorkflowSharesSigningAndReleaseJobsWithThePluginOne(): void
    {
        $yaml = self::file('.github/workflows/theme-release.yml');
        $plugin = self::jobs(self::file('.github/workflows/plugin-release.yml'));
        $theme = self::jobs($yaml);
        self::assertSame(['build', 'sign-upload', 'publish-release'], array_keys($theme));
        self::assertSame($plugin['sign-upload'], $theme['sign-upload'], 'signature et envoi : aucun écart avec les plugins');
        self::assertSame($plugin['publish-release'], $theme['publish-release']);
        self::assertStringContainsString("permissions:
  contents: read
", $yaml);
        self::assertStringContainsString("        default: v1.1
", $yaml);
        self::assertSame(2, substr_count($yaml, 'overwrite: true'));
        self::assertStringNotContainsString('--clobber', $yaml);
    }

    public function testThemeBuildJobChecksTheThemeWithoutSecrets(): void
    {
        $build = self::jobs(self::file('.github/workflows/theme-release.yml'))['build'];
        self::assertStringNotContainsString('secrets.', $build);
        self::assertStringContainsString("if: startsWith(github.ref, 'refs/tags/v')", $build);
        self::assertStringContainsString('timeout-minutes:', $build);
        self::assertSame(substr_count($build, 'actions/checkout@'), substr_count($build, 'persist-credentials: false'));

        $steps = self::steps($build);
        $main = $steps['Version minimale de wawp-theme-updater exigée sur main'] ?? '';
        foreach (['repository: wearewp/release-tools', 'ref: main', 'path: tools-main', 'sparse-checkout: config', 'persist-credentials: false'] as $needle) {
            self::assertStringContainsString($needle, $main, $needle);
        }
        $checks = $steps['Contrôles avant publication'] ?? '';
        foreach (['tools/config/min-theme-updater-version.txt', 'tools-main/config/min-theme-updater-version.txt', 'sort -V | tail -n 1', 'php tools/scripts/check-headers.php --type=theme theme "$SLUG" "$GITHUB_REF_NAME" "$min"'] as $needle) {
            self::assertStringContainsString($needle, $checks, $needle);
        }
        $package = $steps['Paquet'] ?? '';
        $rsync = (int) strpos($package, 'rsync -a --exclude-from=theme/.distignore');
        $check = (int) strpos($package, 'php tools/scripts/check-package.php --type=theme "build/$SLUG" "$SLUG" "$VERSION"');
        $zip = (int) strpos($package, 'zip -rqX');
        self::assertGreaterThan(0, $rsync);
        self::assertLessThan($check, $rsync);
        self::assertLessThan($zip, $check);
    }

    public function testThemeCallerTemplate(): void
    {
        $yaml = self::file('templates/theme-release.yml');
        self::assertStringContainsString("tags: ['v*.*.*']", $yaml);
        self::assertStringContainsString('permissions: {}', $yaml);
        self::assertStringContainsString('uses: wearewp/release-tools/.github/workflows/theme-release.yml@v1.1', $yaml);
        self::assertStringContainsString('secrets: inherit', $yaml);
        self::assertStringContainsString('uses: wearewp/release-tools/.github/workflows/plugin-release.yml@v1', self::file('templates/release.yml'), 'les plugins restent sur v1');
    }
}
