<?php
/**
 * Plugin Name: WAWP Test Plugin
 * Description: Plugin de validation de la chaîne de mise à jour WeAre[WP].
 * Version: {{VERSION}}
 * Requires PHP: 7.4
 * Update URI: https://updates.wearewp.pro
 */

defined( 'ABSPATH' ) || exit;

require __DIR__ . '/wawp-updater/load.php';
\WAWP\Updater\register( __FILE__ );
