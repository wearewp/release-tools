<?php

declare(strict_types=1);

namespace WAWP\ReleaseTools\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WAWP\ReleaseTools\Tests\Support\Cli;

final class KeygenTest extends TestCase
{
    public function testPrintsTwoMatchingPairsAndWritesNothing(): void
    {
        $before = scandir(dirname(__DIR__, 2));
        [$code, $out] = Cli::run([PHP_BINARY, Cli::php('bin/keygen.php')]);
        self::assertSame(0, $code);
        self::assertSame($before, scandir(dirname(__DIR__, 2)));

        self::assertSame(2, preg_match_all('/^Privée[^\n]*:\n([A-Za-z0-9+\/=]{88})$/m', $out, $secrets));
        self::assertSame(2, preg_match_all('/^Publique :\n([A-Za-z0-9+\/=]{44})$/m', $out, $publics));
        foreach ([0, 1] as $i) {
            $secret = (string) base64_decode($secrets[1][$i], true);
            self::assertSame($publics[1][$i], base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret)));
        }
        self::assertNotSame($publics[1][0], $publics[1][1]);
        self::assertStringContainsString("# active\n{$publics[1][0]}\n# secours\n{$publics[1][1]}", $out);
        self::assertStringContainsString("const PUBLIC_KEYS = array( '{$publics[1][0]}', '{$publics[1][1]}' );", $out);
    }
}
