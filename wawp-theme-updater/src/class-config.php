<?php
/**
 * Configuration du client de mise à jour des thèmes WeAre[WP].
 *
 * @package WAWP\ThemeUpdater
 */

namespace WAWP\ThemeUpdater;

defined( 'ABSPATH' ) || exit;

/**
 * Valeurs fixes du client. Elles ne changent qu'avec une nouvelle version de la bibliothèque.
 */
final class Config {

	/** Version de la bibliothèque. */
	const VERSION = '1.0.1';

	/** Hôte de l'en-tête Update URI des thèmes WeAre[WP]. */
	const HOST = 'updates.wearewp.pro';

	/** Adresse du serveur de mises à jour. */
	const ENDPOINT = 'https://updates.wearewp.pro';

	/**
	 * Préfixe de l'adresse de paquet confiée au cache de WordPress (suivi du slug). Ce n'est pas une adresse de
	 * téléchargement : le vrai paquet est demandé avec un jeton frais au moment de l'installation. Elle ne doit
	 * jamais commencer par ENDPOINT . '/v1/download/', préfixe que wawp-updater 1.0.0 (plugins) intercepte et
	 * refuse hors d'une mise à jour de plugin.
	 */
	const PACKAGE_MARKER = self::ENDPOINT . '/v1/theme-update/';

	/**
	 * Clés publiques Ed25519 en base64 : clé active, puis clé de secours. Identiques à celles de wawp-updater.
	 *
	 * @var list<string>
	 */
	const PUBLIC_KEYS = array( 'LHRLyVYY0wTP/uuvzX/VVI0KVdfncIXAnZ/UIPu/xN4=', 'fW/d3eKzXpvuKKeOQEC/RevDVr91YOmgBRXlGVh8e50=' );

	/** Cache de la réponse /v1/check (transient de site), distinct de celui des plugins. */
	const CACHE_KEY = 'wawp_theme_updater_check';

	/** Durée du cache après succès (12 h). */
	const CACHE_TTL = 43200;

	/** Durée du cache après échec ou refus (1 h), pour ne pas inonder le serveur. */
	const ERROR_TTL = 3600;

	/** Délai réseau des appels à l'API. */
	const TIMEOUT = 10;

	/** Pause (en secondes) avant la seconde et dernière demande de jeton frais au téléchargement. */
	const RETRY_DELAY = 1;

	/** Délai de téléchargement d'un paquet. */
	const DOWNLOAD_TIMEOUT = 300;
}
