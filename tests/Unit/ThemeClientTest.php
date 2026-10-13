<?php

declare(strict_types=1);

namespace WAWP\ReleaseTools\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WAWP\ReleaseTools\Tests\Support\Http;
use WAWP\ThemeUpdater\Client;
use WawpTestState;
use WP_Error;

final class ThemeClientTest extends TestCase
{
    private const KEY = 'wawp_AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    protected function setUp(): void
    {
        WawpTestState::reset();
    }

    public function testCheckSendsOnlyTheThemeAndValidatesTheAnswer(): void
    {
        $signature = base64_encode(str_repeat('s', 64));
        WawpTestState::$http = static fn (): array => Http::response(200, Http::theme_body(['wawp-demo-theme' => Http::theme_offer('wawp-demo-theme', '1.4.0', $signature)]));

        $result = (new Client())->check(self::KEY, 'https://client.fr', ['wawp-demo-theme' => '1.3.0']);

        self::assertSame(200, $result['status']);
        self::assertNotNull($result['offers']);
        self::assertSame('1.4.0', $result['offers']['wawp-demo-theme']['version']);
        self::assertSame('https://www.wearewp.pro/', $result['offers']['wawp-demo-theme']['url']);
        [$method, $url, $args] = WawpTestState::$requests[0];
        self::assertSame('POST', $method);
        self::assertSame('https://updates.wearewp.pro/v1/check', $url);
        self::assertSame('Bearer ' . self::KEY, $args['headers']['Authorization']);
        self::assertSame('application/json', $args['headers']['Content-Type']);
        self::assertSame(10, $args['timeout']);
        self::assertSame(0, $args['redirection']);
        self::assertTrue($args['sslverify']);
        self::assertSame(
            ['site' => 'https://client.fr', 'themes' => ['wawp-demo-theme' => '1.3.0'], 'wp' => '6.8.1', 'php' => PHP_VERSION, 'client' => '1.0.0'],
            json_decode($args['body'], true)
        );
        self::assertStringStartsWith('wawp-theme-updater/1.0.0; ', $args['user-agent']);
        self::assertStringNotContainsString(self::KEY, $args['user-agent']);
    }

    public function testStatusIsReportedAndOffersAreNullOnFailure(): void
    {
        $cases = [
            'réseau' => [static fn () => new WP_Error('http_request_failed', 'timeout'), 0],
            'licence refusée' => [static fn (): array => Http::response(401, '{"status":"unauthorized"}'), 401],
            'serveur indisponible' => [static fn (): array => Http::response(503, '{"status":"unavailable"}'), 503],
            'réponse invalide' => [static fn (): array => Http::response(200, 'pas du JSON'), 200],
            'réponse de plugins' => [static fn (): array => Http::response(200, '{"plugins":{}}'), 200],
        ];
        foreach ($cases as $label => [$http, $status]) {
            WawpTestState::$http = $http;
            $result = (new Client())->check(self::KEY, 'https://client.fr', ['wawp-demo-theme' => '1.3.0']);
            self::assertSame($status, $result['status'], $label);
            self::assertNull($result['offers'], $label);
        }
    }
}
