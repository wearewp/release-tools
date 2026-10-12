<?php

declare(strict_types=1);

namespace WAWP\ReleaseTools\Tests\Unit;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use WAWP\ReleaseTools\Tests\Support\Http;
use WAWP\ReleaseTools\Tests\Support\Keys;
use WAWP\Updater\Client;
use WAWP\Updater\Updater;
use WawpTestState;
use WP_Error;

#[RunTestsInSeparateProcesses]
final class UpdaterTest extends TestCase
{
    private const KEY = 'wawp_AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
    private const FILE = WP_PLUGIN_DIR . '/wawp-demo/wawp-demo.php';
    private const BASENAME = 'wawp-demo/wawp-demo.php';

    /** @var array{secret: string, public: string} */
    private array $pair;
    private string $zip;
    private int $token = 0;

    protected function setUp(): void
    {
        define('WAWP_LICENSE_KEY', self::KEY);
        WawpTestState::reset();
        WawpTestState::$versions[self::FILE] = '1.3.0';
        $this->pair = Keys::pair();
        $this->zip = sys_get_temp_dir() . '/wawp-upd-' . bin2hex(random_bytes(4)) . '.zip';
        file_put_contents($this->zip, random_bytes(1024));
        $signature = Keys::sign_file($this->zip, $this->pair['secret']);
        WawpTestState::$http = function (string $method) use ($signature): array {
            $this->token++;

            return $method === 'POST'
                ? Http::response(200, Http::offer_body(['wawp-demo' => Http::offer('1.4.0', $signature, 'jeton' . $this->token)]))
                : Http::response(200, (string) json_encode([
                    'name' => '<b>WAWP Démo</b>', 'slug' => 'wawp-demo', 'version' => '1.4.0', 'requires' => '6.4',
                    'tested' => '6.8', 'requires_php' => '7.4', 'last_updated' => '2026-10-12',
                    'sections' => ['description' => '<p>D</p><script>x</script>', 'changelog' => '<h4>1.4.0</h4>'],
                ]));
        };
        $zip = $this->zip;
        WawpTestState::$download = static function () use ($zip): string {
            $copy = $zip . '.dl';
            copy($zip, $copy);

            return $copy;
        };
    }

    protected function tearDown(): void
    {
        foreach ([$this->zip, $this->zip . '.dl'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private function updater(?array $keys = null): Updater
    {
        return new Updater([self::FILE, WP_PLUGIN_DIR . '/mal-range/autre.php'], new Client(), $keys ?? [$this->pair['public']]);
    }

    public function testHooksAreRegistered(): void
    {
        $this->updater()->hooks();
        foreach (['update_plugins_updates.wearewp.pro', 'plugins_api', 'upgrader_pre_download', 'upgrader_process_complete', 'load-update-core.php', 'admin_notices', 'admin_init'] as $hook) {
            self::assertArrayHasKey($hook, WawpTestState::$hooks, $hook);
        }
        self::assertSame([9], WawpTestState::$priorities['load-update-core.php']);
    }

    public function testUpdateIsOfferedFromOneCachedRequest(): void
    {
        $updater = $this->updater();
        $update = $updater->filter_update(false, [], self::BASENAME, []);
        self::assertIsArray($update);
        self::assertSame('1.4.0', $update['version']);
        self::assertSame('wawp-demo', $update['slug']);
        self::assertSame('https://updates.wearewp.pro/v1/download/jeton1', $update['package']);

        $updater->filter_update(false, [], self::BASENAME, []);
        self::assertCount(1, WawpTestState::$requests, 'la seconde lecture vient du cache');
        self::assertSame(43200, WawpTestState::$transients['wawp_updater_check']['ttl']);
        self::assertStringNotContainsString(self::KEY, (string) json_encode(WawpTestState::$transients));
    }

    public function testForeignPluginIsLeftAlone(): void
    {
        self::assertSame('intact', $this->updater()->filter_update('intact', [], 'autre/autre.php', []));
        self::assertSame([], WawpTestState::$requests);
    }

    public function testServerFailureIsCachedOneHourAndBreaksNothing(): void
    {
        WawpTestState::$http = static fn () => new WP_Error('http_request_failed', 'timeout');
        self::assertFalse($this->updater()->filter_update(false, [], self::BASENAME, []));
        self::assertSame(3600, WawpTestState::$transients['wawp_updater_check']['ttl']);
    }

    public function testMultisiteSendsNetworkUrl(): void
    {
        WawpTestState::$multisite = true;
        $this->updater()->filter_update(false, [], self::BASENAME, []);
        self::assertSame('https://reseau-client.fr', json_decode(WawpTestState::$requests[0][2]['body'], true)['site']);
    }

    public function testPreDownloadUsesFreshTokenAndVerifiesSignature(): void
    {
        $updater = $this->updater();
        $stale = $updater->filter_update(false, [], self::BASENAME, [])['package'];

        $urls = [];
        $zip = $this->zip;
        WawpTestState::$download = static function (string $url) use ($zip, &$urls): string {
            $urls[] = $url;
            copy($zip, $zip . '.dl');

            return $zip . '.dl';
        };
        $path = $updater->filter_pre_download(false, $stale, null, ['plugin' => self::BASENAME]);

        self::assertSame($this->zip . '.dl', $path);
        self::assertCount(2, WawpTestState::$requests, 'un /v1/check frais au téléchargement');
        self::assertSame(['https://updates.wearewp.pro/v1/download/jeton2'], $urls);
    }

    public function testPreDownloadRefusesBadSignatureAndDeletesTheFile(): void
    {
        $result = $this->updater([Keys::pair()['public']])->filter_pre_download(false, 'https://updates.wearewp.pro/v1/download/x', null, ['plugin' => self::BASENAME]);
        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wawp_signature_invalid', $result->get_error_code());
        self::assertFileDoesNotExist($this->zip . '.dl');

        $empty = $this->updater([])->filter_pre_download(false, 'https://updates.wearewp.pro/v1/download/x', null, ['plugin' => self::BASENAME]);
        self::assertSame('wawp_signature_invalid', $empty->get_error_code(), 'aucune clé configurée : refus');
    }

    public function testPreDownloadIgnoresForeignPackagesAndRefusesUnknownOnes(): void
    {
        $updater = $this->updater();
        self::assertFalse($updater->filter_pre_download(false, 'https://downloads.wordpress.org/plugin/x.zip', null, []));
        self::assertSame('deja', $updater->filter_pre_download('deja', 'https://updates.wearewp.pro/v1/download/x', null, []));
        $unknown = $updater->filter_pre_download(false, 'https://updates.wearewp.pro/v1/download/x', null, ['plugin' => 'autre/autre.php']);
        self::assertSame('wawp_unknown_package', $unknown->get_error_code());
    }

    public function testPreDownloadReportsDownloadFailureWithoutLeakingTheToken(): void
    {
        WawpTestState::$download = static fn () => new WP_Error('http_404', 'https://updates.wearewp.pro/v1/download/secret');
        $result = $this->updater()->filter_pre_download(false, 'https://updates.wearewp.pro/v1/download/x', null, ['plugin' => self::BASENAME]);
        self::assertSame('wawp_download_failed', $result->get_error_code());
        self::assertStringNotContainsString('download/', $result->get_error_message());
    }

    public function testPluginsApiReturnsSanitizedDetails(): void
    {
        $args = (object) ['slug' => 'wawp-demo'];
        $info = $this->updater()->filter_plugins_api(false, 'plugin_information', $args);
        self::assertIsObject($info);
        self::assertSame('WAWP Démo', $info->name);
        self::assertSame('<p>D</p>x', $info->sections['description']);
        self::assertSame('https://updates.wearewp.pro/v1/info/wawp-demo', WawpTestState::$requests[0][1]);

        self::assertSame('inchangé', $this->updater()->filter_plugins_api('inchangé', 'plugin_information', (object) ['slug' => 'akismet']));
        self::assertSame('inchangé', $this->updater()->filter_plugins_api('inchangé', 'query_plugins', $args));
    }

    public function testPluginsApiNeverDelegatesARegisteredSlugToWordPressOrg(): void
    {
        WawpTestState::$http = static fn () => new WP_Error('http_request_failed', 'timeout');
        $result = $this->updater()->filter_plugins_api(false, 'plugin_information', (object) ['slug' => 'wawp-demo']);
        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wawp_info_unavailable', $result->get_error_code());
    }

    public function testCacheFlushAndLegacyCleanup(): void
    {
        $updater = $this->updater();
        $updater->filter_update(false, [], self::BASENAME, []);
        $updater->flush_cache();
        self::assertArrayNotHasKey('wawp_updater_check', WawpTestState::$transients);

        WawpTestState::$options['external_updates-wawp-demo'] = 'ancien cache PUC';
        $updater->cleanup_legacy();
        self::assertArrayNotHasKey('external_updates-wawp-demo', WawpTestState::$options);
        self::assertSame(md5('wawp-demo'), WawpTestState::$options['wawp_updater_cleanup']);
    }

    public function testNoNoticeWhenTheKeyIsValid(): void
    {
        WawpTestState::$screen = (object) ['id' => 'plugins'];
        ob_start();
        $this->updater()->admin_notice();
        self::assertSame('', ob_get_clean());
    }
}
