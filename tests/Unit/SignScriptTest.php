<?php

declare(strict_types=1);

namespace WAWP\ReleaseTools\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WAWP\ReleaseTools\Tests\Support\Cli;
use WAWP\ReleaseTools\Tests\Support\Keys;
use WAWP\Updater\Signature;

final class SignScriptTest extends TestCase
{
    private string $zip;

    protected function setUp(): void
    {
        $this->zip = sys_get_temp_dir() . '/wawp-sign-' . bin2hex(random_bytes(4)) . '.zip';
        file_put_contents($this->zip, random_bytes(4096));
    }

    protected function tearDown(): void
    {
        foreach ([$this->zip, $this->zip . '.sig'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testSignatureVerifiesWithTheClientAndKeyIsNeverPrinted(): void
    {
        $pair = Keys::pair();
        [$code, $out, $err] = Cli::run([PHP_BINARY, Cli::php('scripts/sign.php'), $this->zip], ['SIGNING_KEY' => $pair['secret']]);

        self::assertSame(0, $code, $err);
        self::assertFileExists($this->zip . '.sig');
        self::assertTrue(Signature::verify_file($this->zip, (string) file_get_contents($this->zip . '.sig'), [$pair['public']]));
        self::assertStringContainsString($pair['public'], $out);
        self::assertStringNotContainsString($pair['secret'], $out . $err);
    }

    public function testInvalidInputsFailWithoutWritingASignature(): void
    {
        $cases = [
            'clé absente' => [$this->zip, []],
            'clé tronquée' => [$this->zip, ['SIGNING_KEY' => base64_encode('court')]],
            'clé non base64' => [$this->zip, ['SIGNING_KEY' => '***']],
            'paquet absent' => [$this->zip . '.absent', ['SIGNING_KEY' => Keys::pair()['secret']]],
        ];
        foreach ($cases as $label => [$zip, $env]) {
            [$code] = Cli::run([PHP_BINARY, Cli::php('scripts/sign.php'), $zip], $env + ['SIGNING_KEY' => '']);
            self::assertSame(1, $code, $label);
            self::assertFileDoesNotExist($this->zip . '.sig', $label);
        }
    }
}
