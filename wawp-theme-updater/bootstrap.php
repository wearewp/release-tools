<?php
/**
 * Démarrage de wawp-theme-updater.
 *
 * @package WAWP\ThemeUpdater
 */

namespace WAWP\ThemeUpdater;

defined( 'ABSPATH' ) || exit;

// Un fichier manquant (quarantaine antivirus d'hébergeur) ne doit jamais provoquer d'erreur fatale.
foreach ( array( 'config', 'validator', 'theme-validator', 'signature', 'client', 'package', 'updater' ) as $wawp_theme_updater_class ) {
	if ( ! is_readable( __DIR__ . '/src/class-' . $wawp_theme_updater_class . '.php' ) ) {
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature attendue par register().
		return static function ( string $stylesheet, string $dir ): void {};
	}
}

require_once __DIR__ . '/src/class-config.php';
require_once __DIR__ . '/src/class-validator.php';
require_once __DIR__ . '/src/class-theme-validator.php';
require_once __DIR__ . '/src/class-signature.php';
require_once __DIR__ . '/src/class-client.php';
require_once __DIR__ . '/src/class-package.php';
require_once __DIR__ . '/src/class-updater.php';

return static function ( string $stylesheet, string $dir ): void {
	( new Updater( $stylesheet, $dir, new Client(), Config::PUBLIC_KEYS ) )->hooks();
};
