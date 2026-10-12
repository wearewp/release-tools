<?php
/**
 * Démarrage de la copie retenue de wawp-updater.
 *
 * @package WAWP\Updater
 */

namespace WAWP\Updater;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/src/class-config.php';
require_once __DIR__ . '/src/class-validator.php';
require_once __DIR__ . '/src/class-signature.php';
require_once __DIR__ . '/src/class-client.php';
require_once __DIR__ . '/src/class-updater.php';

return static function ( array $plugin_files ): void {
	$files = array();
	foreach ( $plugin_files as $plugin_file ) {
		if ( is_string( $plugin_file ) ) {
			$files[] = $plugin_file;
		}
	}
	( new Updater( $files, new Client(), Config::PUBLIC_KEYS ) )->hooks();
};
