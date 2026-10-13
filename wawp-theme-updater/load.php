<?php
/**
 * Chargeur de wawp-theme-updater, appelé par le functions.php du thème actif :
 *
 *     require __DIR__ . '/wawp-theme-updater/load.php';
 *     \WAWP\ThemeUpdater\register( __DIR__ );
 *
 * Un seul thème est actif : aucun registre partagé ni sélection de copie.
 *
 * @package WAWP\ThemeUpdater
 */

namespace WAWP\ThemeUpdater;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( __NAMESPACE__ . '\register' ) ) {

	/**
	 * Inscrit le thème actif auprès du client de mise à jour. Sans effet si $theme_dir n'est pas le dossier du thème
	 * actif (thème parent, appel parasite), si le client est incomplet ou au second appel. Ne lève jamais d'erreur.
	 * Les points d'accroche sont posés aussitôt : functions.php est chargé avant after_setup_theme, et tous sont
	 * déclenchés plus tard.
	 *
	 * @param mixed $theme_dir Dossier du thème (__DIR__ dans functions.php).
	 * @return void
	 */
	function register( $theme_dir ) {
		static $registered = false;

		try {
			if ( $registered || ! is_string( $theme_dir ) || '' === $theme_dir || ! function_exists( 'get_stylesheet_directory' ) || ! function_exists( 'get_stylesheet' ) ) {
				return;
			}
			$dir    = realpath( $theme_dir );
			$active = realpath( get_stylesheet_directory() );
			if ( false === $dir || false === $active || $dir !== $active || ! is_readable( __DIR__ . '/bootstrap.php' ) ) {
				return;
			}
			$registered = true;

			$start = require __DIR__ . '/bootstrap.php';
			if ( is_callable( $start ) ) {
				$start( (string) get_stylesheet(), $active );
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}
}
