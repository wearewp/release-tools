<?php

declare(strict_types=1);

namespace WAWP\ReleaseTools\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WAWP\ReleaseTools\Tests\Support\Keys;
use WAWP\Updater\Signature;

final class SignatureTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/wawp-sig-' . bin2hex(random_bytes(4)) . '.zip';
        file_put_contents($this->file, random_bytes(2048));
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    public function testActiveAndBackupKeysAreAccepted(): void
    {
        $active = Keys::pair();
        $backup = Keys::pair();
        $keys = [$active['public'], $backup['public']];

        self::assertTrue(Signature::verify_file($this->file, Keys::sign_file($this->file, $active['secret']), $keys));
        self::assertTrue(Signature::verify_file($this->file, Keys::sign_file($this->file, $backup['secret']) . "\n", $keys));
    }

    public function testEverythingElseIsRefused(): void
    {
        $known = Keys::pair();
        $signature = Keys::sign_file($this->file, $known['secret']);

        self::assertFalse(Signature::verify_file($this->file, Keys::sign_file($this->file, Keys::pair()['secret']), [$known['public']]), 'clé inconnue');
        self::assertFalse(Signature::verify_file($this->file, $signature, []), 'aucune clé configurée');
        self::assertFalse(Signature::verify_file($this->file, $signature, ['pas-une-cle', base64_encode('court')]), 'clés malformées');
        self::assertFalse(Signature::verify_file($this->file, 'pas du base64 !', [$known['public']]), 'signature illisible');
        self::assertFalse(Signature::verify_file($this->file, base64_encode('court'), [$known['public']]), 'signature trop courte');
        self::assertFalse(Signature::verify_file($this->file . '.absent', $signature, [$known['public']]), 'fichier absent');

        file_put_contents($this->file, 'x', FILE_APPEND);
        self::assertFalse(Signature::verify_file($this->file, $signature, [$known['public']]), 'fichier modifié');
    }
}
