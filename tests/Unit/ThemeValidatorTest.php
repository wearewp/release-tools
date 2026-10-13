<?php

declare(strict_types=1);

namespace WAWP\ReleaseTools\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WAWP\ReleaseTools\Tests\Support\Http;
use WAWP\ThemeUpdater\Config;
use WAWP\ThemeUpdater\Theme_Validator;

final class ThemeValidatorTest extends TestCase
{
    private const SLUG = 'wawp-demo-theme';

    private static function signature(): string
    {
        return base64_encode(str_repeat('s', 64));
    }

    /** @param array<string, mixed> $changes */
    private static function body(array $changes = []): string
    {
        return Http::theme_body([self::SLUG => $changes + Http::theme_offer(self::SLUG, '1.4.0', self::signature())]);
    }

    public function testConfigValues(): void
    {
        self::assertSame('1.0.0', Config::VERSION);
        self::assertSame('updates.wearewp.pro', Config::HOST);
        self::assertSame('https://updates.wearewp.pro', Config::ENDPOINT);
        self::assertSame('https://updates.wearewp.pro/v1/theme-update/', Config::PACKAGE_MARKER);
        self::assertSame('wawp_theme_updater_check', Config::CACHE_KEY);
        self::assertNotSame(\WAWP\Updater\Config::CACHE_KEY, Config::CACHE_KEY);
        self::assertSame(43200, Config::CACHE_TTL);
        self::assertSame(3600, Config::ERROR_TTL);
        self::assertSame(10, Config::TIMEOUT);
        self::assertSame(300, Config::DOWNLOAD_TIMEOUT);
    }

    public function testValidCheckResponse(): void
    {
        self::assertSame(
            [self::SLUG => [
                'version' => '1.4.0',
                'requires' => '6.1',
                'requires_php' => '7.4',
                'url' => 'https://www.wearewp.pro/',
                'package' => 'https://updates.wearewp.pro/v1/download/abc.def',
                'signature' => self::signature(),
            ]],
            Theme_Validator::check_response(self::body(), [self::SLUG])
        );
    }

    public function testEmptyListsEmptyUrlAndNullRequirementsAreAccepted(): void
    {
        self::assertSame([], Theme_Validator::check_response('{"themes":{}}', [self::SLUG]));
        self::assertSame([], Theme_Validator::check_response('{"themes":[]}', [self::SLUG]));
        $offers = Theme_Validator::check_response(self::body(['url' => '', 'requires' => null, 'requires_php' => null]), [self::SLUG]);
        self::assertNotNull($offers);
        self::assertSame('', $offers[self::SLUG]['url']);
        self::assertNull($offers[self::SLUG]['requires']);
        self::assertNull($offers[self::SLUG]['requires_php']);
    }

    public function testAnyAnomalyRejectsTheWholeResponse(): void
    {
        $good = Http::theme_offer(self::SLUG, '1.4.0', self::signature());
        $cases = [
            'pas du JSON' => 'nope',
            'réponse de plugins' => '{"plugins":{}}',
            'thème non demandé' => Http::theme_body(['theme-intrus' => ['theme' => 'theme-intrus'] + $good]),
            'champ theme différent de la clé' => self::body(['theme' => 'autre-theme']),
            'champ theme absent' => Http::theme_body([self::SLUG => array_diff_key($good, ['theme' => true])]),
            'version invalide' => self::body(['new_version' => '1.4.0-beta']),
            'version absente' => Http::theme_body([self::SLUG => array_diff_key($good, ['new_version' => true])]),
            'version numérique' => self::body(['new_version' => 1.4]),
            'hôte de paquet étranger' => self::body(['package' => 'https://evil.example/v1/download/abc']),
            'préfixe trompeur' => self::body(['package' => 'https://updates.wearewp.pro.evil.example/v1/download/abc']),
            'chemin de paquet détourné' => self::body(['package' => 'https://updates.wearewp.pro/v1/download/../x']),
            'marqueur au lieu d\'un jeton' => self::body(['package' => 'https://updates.wearewp.pro/v1/theme-update/wawp-demo-theme']),
            'signature trop courte' => self::body(['signature' => base64_encode('court')]),
            'requires invalide' => self::body(['requires' => 'latest']),
            'url javascript' => self::body(['url' => 'javascript:alert(1)']),
            'url avec espace' => self::body(['url' => 'https://www.wearewp.pro/a b']),
            'url avec chevron' => self::body(['url' => 'https://www.wearewp.pro/<script>']),
            'url non texte' => self::body(['url' => 42]),
            'url absente' => Http::theme_body([self::SLUG => array_diff_key($good, ['url' => true])]),
            'corps trop volumineux' => '{"themes":{},"x":"' . str_repeat('a', 1048577) . '"}',
        ];
        foreach ($cases as $label => $body) {
            self::assertNull(Theme_Validator::check_response($body, [self::SLUG]), $label);
        }
    }
}
