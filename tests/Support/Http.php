<?php

declare(strict_types=1);

namespace WAWP\ReleaseTools\Tests\Support;

final class Http
{
    /** @return array<string, mixed> */
    public static function response(int $code, string $body): array
    {
        return ['response' => ['code' => $code], 'body' => $body];
    }

    /** @param array<string, array<string, mixed>> $offers */
    public static function offer_body(array $offers): string
    {
        return (string) json_encode(['plugins' => (object) $offers], JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, mixed> */
    public static function offer(string $version, string $signature, string $token = 'abc.def'): array
    {
        return [
            'version' => $version,
            'requires' => '6.4',
            'tested' => '6.8',
            'requires_php' => '7.4',
            'last_updated' => '2026-10-12',
            'package' => 'https://updates.wearewp.pro/v1/download/' . $token,
            'signature' => $signature,
        ];
    }

    /** @param array<string, array<string, mixed>> $offers */
    public static function theme_body(array $offers): string
    {
        return (string) json_encode(['themes' => (object) $offers], JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, mixed> Offre de thème au format de /v1/check (spec thèmes 5.2). */
    public static function theme_offer(string $slug, string $version, string $signature, string $token = 'abc.def'): array
    {
        return [
            'theme' => $slug,
            'new_version' => $version,
            'requires' => '6.1',
            'requires_php' => '7.4',
            'url' => 'https://www.wearewp.pro/',
            'package' => 'https://updates.wearewp.pro/v1/download/' . $token,
            'signature' => $signature,
        ];
    }
}
