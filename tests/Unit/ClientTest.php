<?php

declare(strict_types=1);

namespace WAWP\ReleaseTools\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WAWP\ReleaseTools\Tests\Support\Http;
use WAWP\Updater\Client;
use WawpTestState;
use WP_Error;

final class ClientTest extends TestCase
{
    private const KEY = 'wawp_AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    protected function setUp(): void
    {
        WawpTestState::reset();
    }

    public function testCheckSendsAuthenticatedJsonAndValidatesTheAnswer(): void
    {
        $signature = base64_encode(str_repeat('s', 64));
        WawpTestState::$http = static fn (): array => Http::response(200, Http::offer_body(['wawp-demo' => Http::offer('1.4.0', $signature)]));

        $offers = (new Client())->check(self::KEY, 'https://client.fr', ['wawp-demo' => '1.3.0']);

        self::assertNotNull($offers);
        self::assertSame('1.4.0', $offers['wawp-demo']['version']);
        [$method, $url, $args] = WawpTestState::$requests[0];
        self::assertSame('POST', $method);
        self::assertSame('https://updates.wearewp.pro/v1/check', $url);
        self::assertSame('Bearer ' . self::KEY, $args['headers']['Authorization']);
        self::assertSame(10, $args['timeout']);
        self::assertSame(0, $args['redirection']);
        self::assertTrue($args['sslverify']);
        self::assertSame(
            ['site' => 'https://client.fr', 'plugins' => ['wawp-demo' => '1.3.0'], 'wp' => '6.8.1', 'php' => PHP_VERSION, 'client' => '1.0.0'],
            json_decode($args['body'], true)
        );
        self::assertStringNotContainsString(self::KEY, $args['user-agent']);
    }

    public function testCheckReturnsNullOnErrorStatusOrInvalidBody(): void
    {
        $client = new Client();
        foreach ([
            static fn () => new WP_Error('http_request_failed', 'timeout'),
            static fn (): array => Http::response(401, '{"status":"unauthorized"}'),
            static fn (): array => Http::response(200, 'pas du JSON'),
        ] as $http) {
            WawpTestState::$http = $http;
            self::assertNull($client->check(self::KEY, 'https://client.fr', ['wawp-demo' => '1.3.0']));
        }
    }

    public function testInfoSendsSiteHeaderAndValidatesTheAnswer(): void
    {
        WawpTestState::$http = static fn (): array => Http::response(200, (string) json_encode([
            'name' => 'WAWP Démo', 'slug' => 'wawp-demo', 'version' => '1.4.0', 'requires' => null, 'tested' => null,
            'requires_php' => null, 'last_updated' => null, 'sections' => ['description' => '', 'changelog' => ''],
        ]));

        $info = (new Client())->info(self::KEY, 'https://client.fr', 'wawp-demo');

        self::assertNotNull($info);
        [$method, $url, $args] = WawpTestState::$requests[0];
        self::assertSame('GET', $method);
        self::assertSame('https://updates.wearewp.pro/v1/info/wawp-demo', $url);
        self::assertSame('https://client.fr', $args['headers']['X-WAWP-Site']);
        self::assertSame('Bearer ' . self::KEY, $args['headers']['Authorization']);
    }
}
