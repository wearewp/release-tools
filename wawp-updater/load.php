<?php
/**
 * Chargeur partagé de wawp-updater.
 *
 * Chaque plugin WeAre[WP] embarque une copie de ce dossier. Toutes les copies s'inscrivent, puis seule la
 * plus récente est chargée et gère l'ensemble des plugins. Les fonctions register() et boot() sont figées :
 * leur comportement ne doit jamais changer d'une version à l'autre.
 *
 * @package WAWP\Updater
 */

namespace WAWP\Updater;

defined( 'ABSPATH' ) || exit;

$wawp_updater_version = '1.0.0';

$wawp_updater_registry = $GLOBALS['wawp_updater_registry'] ?? null;
if ( ! is_array( $wawp_updater_registry ) ) {
	$wawp_updater_registry = array(
		'copies'  => array(),
		'plugins' => array(),
		'booted'  => false,
	);
	add_action( 'plugins_loaded', __NAMESPACE__ . '\boot', 0 );
}

$wawp_updater_copies = isset( $wawp_updater_registry['copies'] ) && is_array( $wawp_updater_registry['copies'] ) ? $wawp_updater_registry['copies'] : array();
if ( ! isset( $wawp_updater_copies[ $wawp_updater_version ] ) ) {
	$wawp_updater_copies[ $wawp_updater_version ] = __DIR__;
}
$wawp_updater_registry['copies']  = $wawp_updater_copies;
$GLOBALS['wawp_updater_registry'] = $wawp_updater_registry;

if ( ! function_exists( __NAMESPACE__ . '\register' ) ) {

	/**
	 * Inscrit un plugin auprès du client de mise à jour. Signature figée.
	 *
	 * @param string $plugin_file Fichier principal du plugin (__FILE__).
	 * @return void
	 */
	function register( $plugin_file ) {
		$registry = $GLOBALS['wawp_updater_registry'] ?? null;
		if ( ! is_array( $registry ) ) {
			return;
		}
		$plugins = isset( $registry['plugins'] ) && is_array( $registry['plugins'] ) ? $registry['plugins'] : array();

		$plugins[ (string) $plugin_file ] = true;
		$registry['plugins']              = $plugins;
		$GLOBALS['wawp_updater_registry'] = $registry;
	}

	/**
	 * Charge la copie la plus récente et démarre le client. Comportement figé.
	 *
	 * @return void
	 */
	function boot() {
		try {
			$registry = $GLOBALS['wawp_updater_registry'] ?? null;
			if ( ! is_array( $registry ) || ! empty( $registry['booted'] ) ) {
				return;
			}
			$copies  = isset( $registry['copies'] ) && is_array( $registry['copies'] ) ? $registry['copies'] : array();
			$plugins = isset( $registry['plugins'] ) && is_array( $registry['plugins'] ) ? $registry['plugins'] : array();
			if ( array() === $copies || array() === $plugins ) {
				return;
			}
			$registry['booted']               = true;
			$GLOBALS['wawp_updater_registry'] = $registry;

			// Version la plus haute d'abord ; une copie dont bootstrap.php est illisible (quarantaine antivirus) est sautée.
			$versions = array_map( 'strval', array_keys( $copies ) );
			usort( $versions, 'version_compare' );
			foreach ( array_reverse( $versions ) as $version ) {
				$dir = $copies[ $version ] ?? null;
				if ( ! is_string( $dir ) || ! is_readable( $dir . '/bootstrap.php' ) ) {
					continue;
				}
				$start = require $dir . '/bootstrap.php';
				if ( is_callable( $start ) ) {
					$start( array_map( 'strval', array_keys( $plugins ) ) );
				}
				return;
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}
}

unset( $wawp_updater_version, $wawp_updater_copies );
