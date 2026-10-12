<?php

declare(strict_types=1);

namespace WAWP\ReleaseTools\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WAWP\ReleaseTools\Tests\Support\Cli;

final class SetupRepoTest extends TestCase
{
    // Chemin relatif : sous Windows, bash lancé depuis PHP est celui de WSL, qui ne lit pas E:\.
    private const SCRIPT = 'bin/setup-repo.sh';

    private static function script(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . self::SCRIPT);
    }

    public function testSyntaxIsValid(): void
    {
        [$code, , $err] = Cli::run(['bash', '-n', self::SCRIPT]);
        self::assertSame(0, $code, $err);
    }

    public function testNeverSetsSecretValues(): void
    {
        $script = self::script();
        self::assertStringNotContainsString('gh secret set', $script);
        self::assertStringContainsString('actions/secrets/$secret/repositories/$repo_id', $script);
        foreach (['SIGNING_KEY', 'FTP_HOST', 'FTP_USER', 'FTP_PASSWORD'] as $secret) {
            self::assertStringContainsString($secret, $script);
        }
    }

    public function testRulesetAndGuards(): void
    {
        $script = self::script();
        foreach (['"target": "tag"', 'refs/tags/v*', '"type": "deletion"', '"type": "update"', '"type": "non_fast_forward"', 'Tags de version immuables', 'set -euo pipefail', 'wearewp/'] as $needle) {
            self::assertStringContainsString($needle, $script);
        }
    }

    public function testRulesetListIsCapturedBeforeGrep(): void
    {
        // Sous pipefail, « gh api | grep -q » peut échouer par SIGPIPE et créer un ruleset en double.
        $script = self::script();
        self::assertStringContainsString('rulesets="$(gh api "repos/$repo/rulesets" --jq \'.[].name\')"', $script);
        self::assertStringContainsString('grep -qx \'Tags de version immuables\' <<< "$rulesets"', $script);
        self::assertDoesNotMatchRegularExpression('/\|\s*grep -q/', $script);
    }

    public function testRefusesBadArguments(): void
    {
        [$code, , $err] = Cli::run(['bash', self::SCRIPT, 'autre/depot', '.']);
        self::assertSame(1, $code);
        self::assertStringContainsString('wearewp/', $err);
    }
}
