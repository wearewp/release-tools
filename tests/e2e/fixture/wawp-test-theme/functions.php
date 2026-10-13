<?php
/**
 * Thème de validation de la chaîne de mise à jour WeAre[WP].
 *
 * @package wawp-test-theme
 */

defined( 'ABSPATH' ) || exit;

require __DIR__ . '/wawp-theme-updater/load.php';
\WAWP\ThemeUpdater\register( __DIR__ );
