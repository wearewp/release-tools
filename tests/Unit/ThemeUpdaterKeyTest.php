<?php

declare(strict_types=1);

namespace WAWP\ReleaseTools\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use WAWP\ThemeUpdater\Client;
use WAWP\ThemeUpdater\Updater;
use WawpTestState;

#[RunTestsInSeparateProcesses]
final class ThemeUpdaterKeyTest extends TestCase
{
    private const DIR = '/var/www/wp-content/themes/wawp-demo-theme';

    /** @return array<string, array{mixed}> */
    public static function invalidKeys(): array
    {
        return [
            'absente' => [null],
            'espaces' => [' wawp_' . str_repeat('A', 43) . ' '],
            'entier' => [12345],
            'tronquée' => ['wawp_' . str_repeat('A', 40)],
        ];
    }

    #[DataProvider('invalidKeys')]
    public function testInvalidKeyMeansNoRequestAndNotice(mixed $key): void
    {
        if ($key !== null) {
            define('WAWP_LICENSE_KEY', $key);
        }
        WawpTestState::reset();
        WawpTestState::$versions[self::DIR . '/style.css'] = '1.3.0';
        WawpTestState::$headers[self::DIR . '/style.css'] = ['Text Domain' => 'wawp-demo-theme'];
        WawpTestState::$http = static function (): array {
            throw new \LogicException('aucune requête attendue');
        };
        $updater = new Updater('wawp-demo-theme', self::DIR, new Client(), []);

        self::assertFalse($updater->filter_update(false, [], 'wawp-demo-theme', []));
        self::assertSame([], WawpTestState::$requests);
        $result = $updater->filter_pre_download(false, 'https://updates.wearewp.pro/v1/theme-update/wawp-demo-theme', null, ['theme' => 'wawp-demo-theme']);
        self::assertSame('wawp_theme_token_unavailable', $result->get_error_code());
        self::assertSame([], WawpTestState::$requests);

        WawpTestState::$screen = (object) ['id' => 'themes'];
        ob_start();
        $updater->admin_notice();
        $notice = (string) ob_get_clean();
        self::assertStringContainsString('WAWP_LICENSE_KEY', $notice);
        self::assertStringContainsString('notice-warning', $notice);

        WawpTestState::$can = false;
        ob_start();
        $updater->admin_notice();
        self::assertSame('', ob_get_clean(), 'rien pour un utilisateur sans droit de mise à jour des thèmes');
    }
}
