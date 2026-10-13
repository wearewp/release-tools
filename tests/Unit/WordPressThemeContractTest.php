<?php

declare(strict_types=1);

namespace WAWP\ReleaseTools\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Comportements du cœur de WordPress sur lesquels repose wawp-theme-updater (spec thèmes, section 11),
 * vérifiés dans l'archive de la version épinglée pour le bout en bout.
 */
final class WordPressThemeContractTest extends TestCase
{
    private static function source(string $path): string
    {
        $versions = json_decode((string) file_get_contents(dirname(__DIR__) . '/e2e/versions.json'), true);
        self::assertIsArray($versions);
        $zip = dirname(__DIR__) . '/e2e/cache/wordpress-' . $versions['wordpress']['version'] . '.zip';
        if (!is_file($zip)) {
            self::markTestSkipped('Archive WordPress absente du cache e2e (lancer composer e2e une fois).');
        }
        $archive = new \ZipArchive();
        self::assertTrue($archive->open($zip));
        $content = (string) $archive->getFromName('wordpress/' . $path);
        $archive->close();
        self::assertNotSame('', $content, $path);

        return str_replace("\r", '', $content);
    }

    public function testUpdateThemesFilterSignatureAndResultHandling(): void
    {
        $update = self::source('wp-includes/update.php');
        self::assertStringContainsString('$update = apply_filters( "update_themes_{$hostname}", false, $theme_data, $theme_stylesheet, $locales );', $update);
        self::assertStringContainsString('if ( ! isset( $update->version ) ) {', $update);
        self::assertStringContainsString('$update->new_version = $update->version;', $update);
        self::assertStringContainsString('$new_update->response[ $theme_stylesheet ] = (array) $update;', $update);
        self::assertStringContainsString('$new_update->no_update[ $theme_stylesheet ] = (array) $update;', $update);
        self::assertSame(1, preg_match('/\'UpdateURI\'\s+=>\s*\$theme->get\( \'UpdateURI\' \),/', $update));
    }

    public function testThemeUpgradesCarryTheStylesheetInHookExtra(): void
    {
        $upgrader = self::source('wp-admin/includes/class-theme-upgrader.php');
        // upgrade() (écran Thèmes sans AJAX, mises à jour automatiques) et bulk_upgrade() (Mises à jour, AJAX, WP-CLI).
        self::assertSame(2, preg_match_all('/\'hook_extra\'\s*=>\s*array\(\s*\'theme\'\s*=>\s*\$theme,/', $upgrader));
        // Téléversement d'un zip : pas de clé 'theme', paquet local.
        self::assertSame(1, preg_match('/\'hook_extra\'\s*=>\s*array\(\s*\'type\'\s*=>\s*\'theme\',\s*\'action\'\s*=>\s*\'install\',\s*\)/', $upgrader));
        self::assertSame(1, preg_match('/\'overwrite_package\'\s*=>\s*false,\s*\/\/ Do not overwrite files\./', $upgrader));
    }

    public function testPreDownloadFilterRunsBeforeTheLocalFileShortcut(): void
    {
        $base = self::source('wp-admin/includes/class-wp-upgrader.php');
        $filter = strpos($base, "\$reply = apply_filters( 'upgrader_pre_download', false, \$package, \$this, \$hook_extra );");
        $local = strpos($base, "if ( ! preg_match( '!^(http|https|ftp)://!i', \$package ) && file_exists( \$package ) ) { // Local file or remote?");
        self::assertIsInt($filter);
        self::assertIsInt($local);
        self::assertLessThan($local, $filter);
    }

    public function testAutomaticUpdatesUpgradeTheThemeByItsStylesheet(): void
    {
        $auto = self::source('wp-admin/includes/class-wp-automatic-updater.php');
        self::assertStringContainsString('$upgrader_item = $item->theme;', $auto);
        self::assertStringContainsString('$upgrade_result = $upgrader->upgrade(', $auto);
    }

    public function testFrozenPluginClientRefusesEveryServerDownloadUrlOutsideAPluginUpdate(): void
    {
        // Raison de Config::PACKAGE_MARKER dans wawp-theme-updater.
        $plugin = str_replace("\r", '', (string) file_get_contents(dirname(__DIR__, 2) . '/wawp-updater/src/class-updater.php'));
        self::assertStringContainsString("0 !== strpos( \$package, Config::ENDPOINT . '/v1/download/' )", $plugin);
        self::assertStringContainsString("return new \WP_Error( 'wawp_unknown_package'", $plugin);
        self::assertStringContainsString("add_filter( 'upgrader_pre_download', array( \$this, 'filter_pre_download' ), 1, 4 );", $plugin);
    }
}
