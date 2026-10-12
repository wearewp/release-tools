<?php

declare(strict_types=1);

namespace WAWP\ReleaseTools\Tests\Unit;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use WAWP\Updater\Client;
use WAWP\Updater\Updater;
use WawpTestState;

#[RunTestsInSeparateProcesses]
final class UpdaterKeyTest extends TestCase
{
    private const FILE = WP_PLUGIN_DIR . '/wawp-demo/wawp-demo.php';

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

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidKeys')]
    public function testInvalidKeyMeansNoRequestAndNotice(mixed $key): void
    {
        if ($key !== null) {
            define('WAWP_LICENSE_KEY', $key);
        }
        WawpTestState::reset();
        WawpTestState::$versions[self::FILE] = '1.3.0';
        WawpTestState::$http = static function (): array {
            throw new \LogicException('aucune requête attendue');
        };
        $updater = new Updater([self::FILE], new Client(), []);

        self::assertFalse($updater->filter_update(false, [], 'wawp-demo/wawp-demo.php', []));
        self::assertSame([], WawpTestState::$requests);

        $info = $updater->filter_plugins_api(false, 'plugin_information', (object) ['slug' => 'wawp-demo']);
        self::assertInstanceOf(\WP_Error::class, $info);
        self::assertSame('wawp_info_unavailable', $info->get_error_code());
        self::assertSame([], WawpTestState::$requests);

        WawpTestState::$screen = (object) ['id' => 'plugins'];
        ob_start();
        $updater->admin_notice();
        $notice = (string) ob_get_clean();
        self::assertStringContainsString('WAWP_LICENSE_KEY', $notice);
        self::assertStringContainsString('notice-warning', $notice);

        WawpTestState::$can = false;
        ob_start();
        $updater->admin_notice();
        self::assertSame('', ob_get_clean(), 'rien pour un utilisateur sans droit de mise à jour');
    }
}
