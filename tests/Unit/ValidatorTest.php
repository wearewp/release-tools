<?php

declare(strict_types=1);

namespace WAWP\ReleaseTools\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WAWP\ReleaseTools\Tests\Support\Http;
use WAWP\Updater\Config;
use WAWP\Updater\Validator;

final class ValidatorTest extends TestCase
{
    private static function signature(): string
    {
        return base64_encode(str_repeat('s', 64));
    }

    public function testConfigValues(): void
    {
        self::assertSame('updates.wearewp.pro', Config::HOST);
        self::assertSame('https://updates.wearewp.pro', Config::ENDPOINT);
        self::assertSame(43200, Config::CACHE_TTL);
        self::assertSame(3600, Config::ERROR_TTL);
    }

    public function testKeySlugAndInstalledVersion(): void
    {
        self::assertTrue(Validator::is_key('wawp_' . str_repeat('A', 43)));
        foreach ([' wawp_' . str_repeat('A', 43), 'wawp_' . str_repeat('A', 43) . "\n", 'wawp_' . str_repeat('A', 42), 12345, null] as $bad) {
            self::assertFalse(Validator::is_key($bad), var_export($bad, true));
        }
        self::assertTrue(Validator::is_slug('wawp-agenda'));
        self::assertFalse(Validator::is_slug("wawp-agenda\n"));
        self::assertTrue(Validator::is_installed_version('1.3.2-dev'));
        self::assertFalse(Validator::is_installed_version(''));
    }

    public function testValidCheckResponse(): void
    {
        $body = Http::offer_body(['wawp-demo' => Http::offer('1.4.0', self::signature())]);
        $offers = Validator::check_response($body, ['wawp-demo', 'wawp-autre']);
        self::assertNotNull($offers);
        self::assertSame('1.4.0', $offers['wawp-demo']['version']);
        self::assertSame('https://updates.wearewp.pro/v1/download/abc.def', $offers['wawp-demo']['package']);
        self::assertSame('7.4', $offers['wawp-demo']['requires_php']);
    }

    public function testEmptyListAndEmptyObjectAreBothAccepted(): void
    {
        self::assertSame([], Validator::check_response('{"plugins":{}}', ['wawp-demo']));
        self::assertSame([], Validator::check_response('{"plugins":[]}', ['wawp-demo']));
    }

    public function testAnyAnomalyRejectsTheWholeResponse(): void
    {
        $good = Http::offer('1.4.0', self::signature());
        $cases = [
            'pas du JSON' => 'nope',
            'sans plugins' => '{"status":"ok"}',
            'slug non demandé' => Http::offer_body(['wawp-intrus' => $good]),
            'version invalide' => Http::offer_body(['wawp-demo' => ['version' => '1.4.0-beta'] + $good]),
            'hôte de paquet étranger' => Http::offer_body(['wawp-demo' => ['package' => 'https://evil.example/v1/download/abc'] + $good]),
            'chemin de paquet détourné' => Http::offer_body(['wawp-demo' => ['package' => 'https://updates.wearewp.pro/v1/download/../x'] + $good]),
            'préfixe trompeur' => Http::offer_body(['wawp-demo' => ['package' => 'https://updates.wearewp.pro.evil.example/v1/download/abc'] + $good]),
            'signature trop courte' => Http::offer_body(['wawp-demo' => ['signature' => base64_encode('court')] + $good]),
            'signature non base64' => Http::offer_body(['wawp-demo' => ['signature' => '***'] + $good]),
            'tested invalide' => Http::offer_body(['wawp-demo' => ['tested' => 'latest'] + $good]),
            'date invalide' => Http::offer_body(['wawp-demo' => ['last_updated' => 'hier'] + $good]),
        ];
        foreach ($cases as $label => $body) {
            self::assertNull(Validator::check_response($body, ['wawp-demo']), $label);
        }
    }

    public function testInfoResponse(): void
    {
        $body = (string) json_encode([
            'name' => 'WAWP Démo',
            'slug' => 'wawp-demo',
            'version' => '1.4.0',
            'requires' => '6.4',
            'tested' => '6.8',
            'requires_php' => '7.4',
            'last_updated' => '2026-10-12',
            'sections' => ['description' => '<p>Desc</p>', 'changelog' => '<h4>1.4.0</h4>'],
        ]);
        $info = Validator::info_response($body, 'wawp-demo');
        self::assertNotNull($info);
        self::assertSame('WAWP Démo', $info['name']);
        self::assertSame('<h4>1.4.0</h4>', $info['sections']['changelog']);

        self::assertNull(Validator::info_response($body, 'wawp-autre'), 'slug différent');
        self::assertNull(Validator::info_response(str_replace('WAWP D\u00e9mo', '', $body), 'wawp-demo'), 'nom vide');
        self::assertNull(Validator::info_response('{"name":"x"}', 'wawp-demo'), 'incomplet');
    }
}
