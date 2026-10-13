<?php

declare(strict_types=1);

namespace WAWP\ReleaseTools\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use WAWP\ReleaseTools\Tests\Support\Http;
use WAWP\ReleaseTools\Tests\Support\Keys;
use WAWP\ThemeUpdater\Client;
use WAWP\ThemeUpdater\Updater;
use WawpTestState;
use WP_Error;

#[RunTestsInSeparateProcesses]
final class ThemeUpdaterTest extends TestCase
{
    private const KEY = 'wawp_AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
    private const SLUG = 'wawp-demo-theme';
    private const DIR = '/var/www/wp-content/themes/wawp-demo-theme';
    private const STYLE = self::DIR . '/style.css';
    private const MARKER = 'https://updates.wearewp.pro/v1/theme-update/wawp-demo-theme';

    /** @var array{secret: string, public: string} */
    private array $pair;
    private string $zip;
    private int $token = 0;

    protected function setUp(): void
    {
        define('WAWP_LICENSE_KEY', self::KEY);
        WawpTestState::reset();
        WawpTestState::$versions[self::STYLE] = '1.3.0';
        WawpTestState::$headers[self::STYLE] = ['Text Domain' => self::SLUG];
        $this->pair = Keys::pair();
        $this->zip = sys_get_temp_dir() . '/wawp-theme-upd-' . bin2hex(random_bytes(4)) . '.zip';
        $this->serve('1.4.0', ThemePackageTest::entries(self::SLUG, '1.4.0'));
        $zip = $this->zip;
        WawpTestState::$download = static function () use ($zip): string {
            copy($zip, $zip . '.dl');

            return $zip . '.dl';
        };
    }

    /**
     * Le serveur propose $offer ; le paquet signé contient $entries.
     *
     * @param array<string, string> $entries
     */
    private function serve(string $offer, array $entries): void
    {
        if (is_file($this->zip)) {
            unlink($this->zip);
        }
        PackageTest::zip($this->zip, $entries);
        $signature = Keys::sign_file($this->zip, $this->pair['secret']);
        WawpTestState::$http = function () use ($signature, $offer): array {
            $this->token++;

            return Http::response(200, Http::theme_body([self::SLUG => Http::theme_offer(self::SLUG, $offer, $signature, 'jeton' . $this->token)]));
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

    /** @param list<string>|null $keys */
    private function updater(?array $keys = null): Updater
    {
        // Pause nulle avant la seconde tentative du jeton frais : les tests n'attendent pas.
        return new Updater(self::SLUG, self::DIR, new Client(), $keys ?? [$this->pair['public']], 0);
    }

    /**
     * Réponses successives de /v1/check : null pour une erreur réseau, sinon [statut, corps] ; 'offre' pour l'offre signée.
     *
     * @param list<array{int, string}|string|null> $answers
     */
    private function answers(array $answers): void
    {
        $signature = Keys::sign_file($this->zip, $this->pair['secret']);
        WawpTestState::$http = static function () use (&$answers, $signature) {
            $answer = array_shift($answers);
            if (null === $answer) {
                return new WP_Error('http_request_failed', 'cURL error 28: Operation timed out');
            }
            if ('offre' === $answer) {
                return Http::response(200, Http::theme_body([self::SLUG => Http::theme_offer(self::SLUG, '1.4.0', $signature, 'jeton-frais')]));
            }

            return Http::response($answer[0], $answer[1]);
        };
    }

    public function testFreshTokenIsRequestedASecondTimeAfterANetworkFailure(): void
    {
        $this->answers([null, 'offre']);
        $path = $this->updater()->filter_pre_download(false, self::MARKER, null, ['theme' => self::SLUG]);

        self::assertSame($this->zip . '.dl', $path, 'fichier vérifié après la seconde tentative');
        self::assertCount(2, WawpTestState::$requests);
        self::assertSame('https://updates.wearewp.pro/v1/check', WawpTestState::$requests[1][1]);
        $cache = WawpTestState::$transients['wawp_theme_updater_check'];
        self::assertSame(43200, $cache['ttl'], 'la seconde tentative réussie écrit le cache de succès');
        self::assertFalse($cache['value']['denied']);
    }

    public function testTwoFailuresReportTheCauseOfTheSecondAttempt(): void
    {
        $this->answers([null, [503, '{"status":"unavailable"}'], 'offre']);
        $result = $this->updater()->filter_pre_download(false, self::MARKER, null, ['theme' => self::SLUG]);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wawp_theme_token_unavailable', $result->get_error_code());
        self::assertSame('Mise à jour du thème WeAre[WP] indisponible (HTTP 503) : licence refusée, réponse invalide ou serveur injoignable.', $result->get_error_message());
        self::assertCount(2, WawpTestState::$requests, 'jamais plus de deux requêtes /v1/check par téléchargement');
        self::assertSame(3600, WawpTestState::$transients['wawp_theme_updater_check']['ttl']);
        self::assertStringNotContainsString(self::KEY, $result->get_error_message());
    }

    public function testServerErrorThenNetworkFailureReportsTheNetworkCause(): void
    {
        $this->answers([[503, '{"status":"unavailable"}'], null, 'offre']);
        $result = $this->updater()->filter_pre_download(false, self::MARKER, null, ['theme' => self::SLUG]);

        self::assertStringContainsString('(réseau : http_request_failed cURL error 28: Operation timed out)', $result->get_error_message());
        self::assertStringNotContainsString(self::KEY, $result->get_error_message());
        self::assertCount(2, WawpTestState::$requests, 'jamais plus de deux requêtes /v1/check par téléchargement');
    }

    public function testRefusedLicenceIsNotRetried(): void
    {
        $this->answers([[401, '{"status":"unauthorized"}'], 'offre']);
        $result = $this->updater()->filter_pre_download(false, self::MARKER, null, ['theme' => self::SLUG]);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wawp_theme_token_unavailable', $result->get_error_code());
        self::assertStringContainsString('(HTTP 401)', $result->get_error_message());
        self::assertCount(1, WawpTestState::$requests, 'refus de licence : pas de seconde tentative');
        self::assertTrue(WawpTestState::$transients['wawp_theme_updater_check']['value']['denied']);
    }

    public function testInvalidAnswerIsReportedAsSuch(): void
    {
        $this->answers([[200, 'pas du JSON'], [200, '{"plugins":{}}']]);
        $result = $this->updater()->filter_pre_download(false, self::MARKER, null, ['theme' => self::SLUG]);

        self::assertSame('Mise à jour du thème WeAre[WP] indisponible (réponse invalide) : licence refusée, réponse invalide ou serveur injoignable.', $result->get_error_message());
        self::assertCount(2, WawpTestState::$requests);
    }

    private function notice(Updater $updater, string $screen): string
    {
        WawpTestState::$screen = (object) ['id' => $screen];
        ob_start();
        $updater->admin_notice();

        return (string) ob_get_clean();
    }

    public function testHooksAreRegistered(): void
    {
        $this->updater()->hooks();
        foreach (['update_themes_updates.wearewp.pro', 'upgrader_pre_download', 'upgrader_process_complete', 'load-update-core.php', 'admin_notices', 'network_admin_notices'] as $hook) {
            self::assertArrayHasKey($hook, WawpTestState::$hooks, $hook);
        }
        self::assertSame([1], WawpTestState::$priorities['upgrader_pre_download']);
        self::assertSame([9], WawpTestState::$priorities['load-update-core.php']);
        self::assertArrayNotHasKey('update_plugins_updates.wearewp.pro', WawpTestState::$hooks);
    }

    public function testUpdateIsOfferedFromOneCachedRequestWithoutTokenInWordPressCache(): void
    {
        $updater = $this->updater();
        $update = $updater->filter_update(false, [], self::SLUG, []);
        self::assertSame(
            [
                'theme' => self::SLUG,
                'version' => '1.4.0',
                'url' => 'https://www.wearewp.pro/',
                'package' => self::MARKER,
                'requires' => '6.1',
                'requires_php' => '7.4',
            ],
            $update
        );

        $updater->filter_update(false, [], self::SLUG, []);
        self::assertCount(1, WawpTestState::$requests, 'la seconde lecture vient du cache');
        $cache = WawpTestState::$transients['wawp_theme_updater_check'];
        self::assertSame(43200, $cache['ttl']);
        self::assertFalse($cache['value']['denied']);
        self::assertStringNotContainsString(self::KEY, (string) json_encode(WawpTestState::$transients));

        [, $url, $args] = WawpTestState::$requests[0];
        self::assertSame('https://updates.wearewp.pro/v1/check', $url);
        $body = json_decode($args['body'], true);
        self::assertSame([self::SLUG => '1.3.0'], $body['themes']);
        self::assertArrayNotHasKey('plugins', $body);
        self::assertSame('https://client.fr', $body['site']);
    }

    public function testOtherThemesAreLeftAlone(): void
    {
        self::assertSame('intact', $this->updater()->filter_update('intact', [], 'twentytwentyfive', []));
        self::assertSame([], WawpTestState::$requests);
    }

    public function testUpToDateThemeIsReportedAsNoUpdate(): void
    {
        WawpTestState::$http = static fn () => Http::response(200, Http::theme_body([]));
        self::assertSame(
            ['theme' => self::SLUG, 'version' => '1.3.0', 'url' => '', 'package' => ''],
            $this->updater()->filter_update(false, [], self::SLUG, [])
        );
    }

    public function testServerFailureIsCachedOneHourAndBreaksNothing(): void
    {
        WawpTestState::$http = static fn () => new WP_Error('http_request_failed', 'timeout');
        $updater = $this->updater();
        $update = $updater->filter_update(false, [], self::SLUG, []);
        self::assertSame('', $update['package'], 'aucun paquet proposé');
        self::assertSame(3600, WawpTestState::$transients['wawp_theme_updater_check']['ttl']);
        self::assertFalse(WawpTestState::$transients['wawp_theme_updater_check']['value']['denied']);
        self::assertSame('', $this->notice($updater, 'themes'), 'panne réseau : aucune notice de licence');
    }

    public function testRefusedLicenceIsCachedAndShownOnTheThemesScreen(): void
    {
        WawpTestState::$http = static fn () => Http::response(401, '{"status":"unauthorized"}');
        $updater = $this->updater();
        $updater->filter_update(false, [], self::SLUG, []);
        self::assertTrue(WawpTestState::$transients['wawp_theme_updater_check']['value']['denied']);
        self::assertSame(3600, WawpTestState::$transients['wawp_theme_updater_check']['ttl']);

        self::assertStringContainsString('a refusé la licence', $this->notice($updater, 'themes'));
        self::assertStringContainsString('a refusé la licence', $this->notice($updater, 'themes-network'));
        self::assertSame('', $this->notice($updater, 'plugins'), 'uniquement sur l\'écran Thèmes');
        WawpTestState::$can = false;
        self::assertSame('', $this->notice($updater, 'themes'), 'rien sans le droit update_themes');
    }

    public function testFolderNotMatchingTheTextDomainDisablesUpdatesAndShowsNotice(): void
    {
        WawpTestState::$headers[self::STYLE] = ['Text Domain' => 'theme-client-x'];
        $updater = $this->updater();
        self::assertFalse($updater->filter_update(false, [], self::SLUG, []));
        self::assertSame([], WawpTestState::$requests, 'aucune requête avec un slug faux');
        self::assertStringContainsString('dossier du thème actif', $this->notice($updater, 'themes'));
    }

    public function testInvalidFolderNameIsIgnored(): void
    {
        $updater = new Updater('Wawp Demo Theme-main', self::DIR, new Client(), [$this->pair['public']]);
        self::assertFalse($updater->filter_update(false, [], 'Wawp Demo Theme-main', []));
        self::assertSame([], WawpTestState::$requests);
        self::assertStringContainsString('dossier du thème actif', $this->notice($updater, 'themes'));
    }

    public function testMultisiteSendsNetworkUrl(): void
    {
        WawpTestState::$multisite = true;
        $this->updater()->filter_update(false, [], self::SLUG, []);
        self::assertSame('https://reseau-client.fr', json_decode(WawpTestState::$requests[0][2]['body'], true)['site']);
    }

    public function testExceptionInsideTheHttpLayerNeverEscapes(): void
    {
        WawpTestState::$http = static function (): array {
            throw new \RuntimeException('panne');
        };
        $updater = $this->updater();
        self::assertFalse($updater->filter_update(false, [], self::SLUG, []));
        $result = $updater->filter_pre_download(false, self::MARKER, null, ['theme' => self::SLUG]);
        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wawp_theme_updater_error', $result->get_error_code());
    }

    public function testPreDownloadUsesFreshTokenAndVerifiesSignature(): void
    {
        $updater = $this->updater();
        $package = $updater->filter_update(false, [], self::SLUG, [])['package'];

        $urls = [];
        $zip = $this->zip;
        WawpTestState::$download = static function (string $url) use ($zip, &$urls): string {
            $urls[] = $url;
            copy($zip, $zip . '.dl');

            return $zip . '.dl';
        };
        $path = $updater->filter_pre_download(false, $package, null, ['theme' => self::SLUG, 'type' => 'theme', 'action' => 'update']);

        self::assertSame($this->zip . '.dl', $path);
        self::assertCount(2, WawpTestState::$requests, 'un /v1/check frais au téléchargement');
        self::assertSame(['https://updates.wearewp.pro/v1/download/jeton2'], $urls);
    }

    public function testExceptionAfterTheDownloadDeletesTheTemporaryFile(): void
    {
        $zip = $this->zip;
        WawpTestState::$download = static function () use ($zip): string {
            copy($zip, $zip . '.dl');
            // La lecture de style.css échoue une fois le fichier téléchargé et signé.
            WawpTestState::$versions[self::STYLE] = new \RuntimeException('panne');

            return $zip . '.dl';
        };
        $result = $this->updater()->filter_pre_download(false, self::MARKER, null, ['theme' => self::SLUG]);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wawp_theme_updater_error', $result->get_error_code());
        self::assertFileDoesNotExist($this->zip . '.dl', 'le fichier temporaire ne doit pas rester sur le disque');
    }

    public function testPreDownloadWorksWithBulkUpgradeContext(): void
    {
        // bulk_upgrade() (écran Mises à jour, AJAX, WP-CLI) ne passe que 'theme' et 'temp_backup'.
        $path = $this->updater()->filter_pre_download(false, self::MARKER, null, ['theme' => self::SLUG, 'temp_backup' => ['slug' => self::SLUG]]);
        self::assertSame($this->zip . '.dl', $path);
    }

    public function testPreDownloadIgnoresPackagesThatAreNotThemeMarkers(): void
    {
        $updater = $this->updater();
        self::assertFalse($updater->filter_pre_download(false, 'https://downloads.wordpress.org/theme/x.zip', null, ['theme' => 'x']));
        self::assertSame('deja', $updater->filter_pre_download('deja', 'https://downloads.wordpress.org/theme/x.zip', null, []));
        self::assertFalse($updater->filter_pre_download(false, 'https://updates.wearewp.pro/v1/download/abc.def', null, ['plugin' => 'wawp-demo/wawp-demo.php']), 'paquet de plugin laissé à wawp-updater');
        self::assertFalse($updater->filter_pre_download(false, '/tmp/televerse.zip', null, ['type' => 'theme', 'action' => 'install']), 'téléversement manuel');
        self::assertSame([], WawpTestState::$requests);
    }

    public function testPreDownloadRefusesUnknownOrHijackedMarkers(): void
    {
        $updater = $this->updater();
        self::assertSame('wawp_theme_unknown_package', $updater->filter_pre_download(false, self::MARKER, null, ['theme' => 'autre-theme'])->get_error_code());
        self::assertSame('wawp_theme_unknown_package', $updater->filter_pre_download(false, 'https://updates.wearewp.pro/v1/theme-update/autre-theme', null, ['theme' => self::SLUG])->get_error_code());
        self::assertSame('wawp_theme_unknown_package', $updater->filter_pre_download(false, self::MARKER, null, null)->get_error_code());
        self::assertSame('wawp_theme_package_hijacked', $updater->filter_pre_download('/tmp/autre.zip', self::MARKER, null, ['theme' => self::SLUG])->get_error_code());
        self::assertSame([], WawpTestState::$requests);
    }

    public function testFrozenPluginClientLeavesTheThemeMarkerAlone(): void
    {
        $file = WP_PLUGIN_DIR . '/wawp-demo/wawp-demo.php';
        WawpTestState::$versions[$file] = '1.0.0';
        (new \WAWP\Updater\Updater([$file], new \WAWP\Updater\Client(), []))->hooks();
        $this->updater()->hooks();

        // Ordre réel sur un site : wawp-updater s'inscrit d'abord (plugins_loaded), à la même priorité 1.
        self::assertCount(2, WawpTestState::$hooks['upgrader_pre_download'], 'wawp-updater et wawp-theme-updater sont tous deux inscrits');
        $reply = false;
        foreach (WawpTestState::$hooks['upgrader_pre_download'] as $callback) {
            $reply = $callback($reply, self::MARKER, null, ['theme' => self::SLUG, 'type' => 'theme', 'action' => 'update']);
        }
        self::assertSame($this->zip . '.dl', $reply, 'wawp-updater laisse passer le marqueur, wawp-theme-updater fournit le paquet vérifié');
    }

    public function testPreDownloadRefusesBadSignatureAndDeletesTheFile(): void
    {
        $result = $this->updater([Keys::pair()['public']])->filter_pre_download(false, self::MARKER, null, ['theme' => self::SLUG]);
        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wawp_theme_signature_invalid', $result->get_error_code());
        self::assertSame('Signature du thème WeAre[WP] invalide : mise à jour annulée.', $result->get_error_message());
        self::assertFileDoesNotExist($this->zip . '.dl');

        $empty = $this->updater([])->filter_pre_download(false, self::MARKER, null, ['theme' => self::SLUG]);
        self::assertSame('wawp_theme_signature_invalid', $empty->get_error_code(), 'aucune clé configurée : refus');
    }

    /** @return array<string, array{string, array<string, string>, string}> */
    public static function mismatches(): array
    {
        return [
            'autre version signée (retour arrière)' => ['1.4.0', ThemePackageTest::entries(self::SLUG, '1.2.0'), '1.3.0'],
            'autre slug signé (substitution)' => ['1.4.0', ThemePackageTest::entries('theme-autre', '1.4.0'), '1.3.0'],
            'version égale à l\'installée' => ['1.4.0', ThemePackageTest::entries(self::SLUG, '1.4.0'), '1.4.0'],
            'version inférieure à l\'installée' => ['1.4.0', ThemePackageTest::entries(self::SLUG, '1.4.0'), '1.5.0'],
            'entrée hors du dossier du thème' => ['1.4.0', ThemePackageTest::entries(self::SLUG, '1.4.0') + ['autre/x.php' => '<?php'], '1.3.0'],
        ];
    }

    /** @param array<string, string> $entries */
    #[DataProvider('mismatches')]
    public function testPreDownloadRefusesSignedPackageThatDoesNotMatchTheOffer(string $offer, array $entries, string $installed): void
    {
        WawpTestState::$versions[self::STYLE] = $installed;
        $this->serve($offer, $entries);
        $result = $this->updater()->filter_pre_download(false, self::MARKER, null, ['theme' => self::SLUG]);
        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wawp_theme_package_mismatch', $result->get_error_code());
        self::assertFileDoesNotExist($this->zip . '.dl');
    }

    public function testPreDownloadReportsFailuresWithoutLeakingTheToken(): void
    {
        WawpTestState::$download = static fn () => new WP_Error('http_404', 'https://updates.wearewp.pro/v1/download/secret');
        $result = $this->updater()->filter_pre_download(false, self::MARKER, null, ['theme' => self::SLUG]);
        self::assertSame('wawp_theme_download_failed', $result->get_error_code());
        self::assertStringNotContainsString('download/', $result->get_error_message());

        WawpTestState::$http = static fn () => Http::response(401, '{"status":"unauthorized"}');
        $refused = $this->updater()->filter_pre_download(false, self::MARKER, null, ['theme' => self::SLUG]);
        self::assertSame('wawp_theme_token_unavailable', $refused->get_error_code());
    }

    public function testCacheIsFlushedAfterUpgradesAndOnForceCheck(): void
    {
        $updater = $this->updater();
        $updater->filter_update(false, [], self::SLUG, []);
        $updater->flush_cache();
        self::assertArrayNotHasKey('wawp_theme_updater_check', WawpTestState::$transients);

        $updater->filter_update(false, [], self::SLUG, []);
        $updater->maybe_flush_on_force_check();
        self::assertArrayHasKey('wawp_theme_updater_check', WawpTestState::$transients, 'sans force-check, cache conservé');
        $_GET['force-check'] = '1';
        $updater->maybe_flush_on_force_check();
        unset($_GET['force-check']);
        self::assertArrayNotHasKey('wawp_theme_updater_check', WawpTestState::$transients);
    }

    public function testNoNoticeWhenEverythingIsFine(): void
    {
        $updater = $this->updater();
        $updater->filter_update(false, [], self::SLUG, []);
        self::assertSame('', $this->notice($updater, 'themes'));
    }
}
