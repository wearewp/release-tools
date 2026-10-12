<?php
/**
 * Configuration du client de mise à jour WeAre[WP].
 *
 * @package WAWP\Updater
 */

namespace WAWP\Updater;

defined( 'ABSPATH' ) || exit;

/**
 * Valeurs fixes du client. Elles ne changent qu'avec une nouvelle version de la bibliothèque.
 */
final class Config {

	/** Version de la bibliothèque, identique à $wawp_updater_version dans load.php. */
	const VERSION = '1.0.0';

	/** Hôte de l'en-tête Update URI des plugins WeAre[WP]. */
	const HOST = 'updates.wearewp.pro';

	/** Adresse du serveur de mises à jour. */
	const ENDPOINT = 'https://updates.wearewp.pro';

	/**
	 * Clés publiques Ed25519 en base64 : clé active, puis clé de secours.
	 * Tant que la liste est vide, toute mise à jour est refusée.
	 *
	 * @var list<string>
	 */
	const PUBLIC_KEYS = array( 'LHRLyVYY0wTP/uuvzX/VVI0KVdfncIXAnZ/UIPu/xN4=', 'fW/d3eKzXpvuKKeOQEC/RevDVr91YOmgBRXlGVh8e50=' );

	/** Cache de la réponse /v1/check (transient de site). */
	const CACHE_KEY = 'wawp_updater_check';

	/** Préfixe du cache des réponses /v1/info. */
	const INFO_CACHE_PREFIX = 'wawp_updater_info_';

	/** Durée du cache après succès (12 h). */
	const CACHE_TTL = 43200;

	/** Durée du cache après échec (1 h), pour ne pas inonder le serveur. */
	const ERROR_TTL = 3600;

	/** Délai réseau des appels à l'API. */
	const TIMEOUT = 10;

	/** Délai de téléchargement d'un paquet. */
	const DOWNLOAD_TIMEOUT = 300;
}
