<?php

declare(strict_types=1);

namespace WAWP\ReleaseTools\Tests\Support;

final class Keys
{
    /** @return array{secret: string, public: string} */
    public static function pair(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return [
            'secret' => base64_encode(sodium_crypto_sign_secretkey($pair)),
            'public' => base64_encode(sodium_crypto_sign_publickey($pair)),
        ];
    }

    public static function sign_file(string $path, string $secret_b64): string
    {
        $secret = (string) base64_decode($secret_b64, true);

        return base64_encode(sodium_crypto_sign_detached((string) hash_file('sha384', $path, true), $secret));
    }
}
