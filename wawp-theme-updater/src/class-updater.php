<?php
/**
 * Points d'accroche WordPress du client de mise à jour des thèmes.
 *
 * @package WAWP\ThemeUpdater
 */

namespace WAWP\ThemeUpdater;

defined( 'ABSPATH' ) || exit;

/**
 * Gère le thème actif inscrit. Aucune erreur ne doit interrompre WordPress.
 */
final class Updater {

	/**
	 * Nom du dossier du thème actif (get_stylesheet()).
	 *
	 * @var string
	 */
	private $stylesheet;

	/**
	 * Chemin du dossier du thème actif.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * @var Client
	 */
	private $client;

	/**
	 * @var string[]
	 */
	private $public_keys;

	/**
	 * @param string   $stylesheet  Nom du dossier du thème actif.
	 * @param string   $dir         Chemin du dossier du thème actif.
	 * @param Client   $client      Client HTTP.
	 * @param string[] $public_keys Clés publiques Ed25519 en base64.
	 */
	public function __construct( string $stylesheet, string $dir, Client $client, array $public_keys ) {
		$this->stylesheet  = $stylesheet;
		$this->dir         = $dir;
		$this->client      = $client;
		$this->public_keys = $public_keys;
	}

	/**
	 * Enregistre les points d'accroche.
	 */
	public function hooks(): void {
		add_filter( 'update_themes_' . Config::HOST, array( $this, 'filter_update' ), 10, 4 );
		add_filter( 'upgrader_pre_download', array( $this, 'filter_pre_download' ), 1, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'flush_cache' ), 10, 0 );
		add_action( 'load-update-core.php', array( $this, 'maybe_flush_on_force_check' ), 9 );
		add_action( 'admin_notices', array( $this, 'admin_notice' ) );
		add_action( 'network_admin_notices', array( $this, 'admin_notice' ) );
	}

	/**
	 * Filtre update_themes_{hôte} : données de mise à jour du thème inscrit.
	 *
	 * @param mixed $update           Valeur reçue (false par défaut).
	 * @param mixed $theme_data       En-têtes du thème.
	 * @param mixed $theme_stylesheet Nom du dossier du thème.
	 * @param mixed $locales          Langues installées.
	 * @return mixed
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature imposée par le filtre update_themes_{hôte} (4 arguments).
	public function filter_update( $update, $theme_data, $theme_stylesheet, $locales ) {
		try {
			$slug = $this->slug();
			if ( null === $slug || $theme_stylesheet !== $slug ) {
				return $update;
			}
			$offers = $this->offers( false );
			if ( ! isset( $offers[ $slug ] ) ) {
				$installed = $this->installed();
				if ( null === $this->key() || null === $installed ) {
					return $update;
				}

				// Thème à jour : WordPress le range dans no_update (bascule des mises à jour automatiques).
				return array(
					'theme'   => $slug,
					'version' => $installed,
					'url'     => '',
					'package' => '',
				);
			}
			$offer = $offers[ $slug ];

			// Aucun jeton dans le cache de WordPress : le paquet est demandé avec un jeton frais au téléchargement.
			return array_filter(
				array(
					'theme'        => $slug,
					'version'      => $offer['version'],
					'url'          => $offer['url'],
					'package'      => Config::PACKAGE_MARKER . $slug,
					'requires'     => $offer['requires'],
					'requires_php' => $offer['requires_php'],
				),
				static function ( $value ) {
					return null !== $value;
				}
			);
		} catch ( \Throwable $e ) {
			return $update;
		}
	}

	/**
	 * Filtre upgrader_pre_download : jeton frais, téléchargement, signature, contrôle du paquet.
	 *
	 * @param mixed $reply      Valeur reçue (false si personne n'a encore traité le paquet).
	 * @param mixed $package    Adresse du paquet (marqueur PACKAGE_MARKER pour le thème inscrit).
	 * @param mixed $upgrader   Instance de WP_Upgrader.
	 * @param mixed $hook_extra Contexte de la mise à jour.
	 * @return mixed Chemin du fichier vérifié, WP_Error, ou la valeur reçue.
	 */
	public function filter_pre_download( $reply, $package, $upgrader, $hook_extra ) {
		if ( ! is_string( $package ) || 0 !== strpos( $package, Config::PACKAGE_MARKER ) ) {
			return $reply;
		}
		try {
			if ( false !== $reply ) {
				// Enregistré en priorité 1 : un autre filtre qui a déjà fourni un fichier court-circuiterait la signature.
				return new \WP_Error( 'wawp_theme_package_hijacked', __( 'Paquet de thème WeAre[WP] déjà pris en charge par une autre extension : mise à jour annulée.', 'wawp-theme-updater' ) );
			}
			$slug  = $this->slug();
			$theme = is_array( $hook_extra ) && isset( $hook_extra['theme'] ) && is_string( $hook_extra['theme'] ) ? $hook_extra['theme'] : '';
			if ( null === $slug || $theme !== $slug || Config::PACKAGE_MARKER . $slug !== $package ) {
				return new \WP_Error( 'wawp_theme_unknown_package', __( 'Paquet de thème WeAre[WP] inconnu : mise à jour annulée.', 'wawp-theme-updater' ) );
			}
			$offers = $this->offers( true );
			if ( ! isset( $offers[ $slug ] ) ) {
				return new \WP_Error( 'wawp_theme_token_unavailable', __( 'Mise à jour du thème WeAre[WP] indisponible : licence refusée ou serveur injoignable.', 'wawp-theme-updater' ) );
			}
			$offer = $offers[ $slug ];
			$file  = download_url( $offer['package'], Config::DOWNLOAD_TIMEOUT );
			if ( is_wp_error( $file ) ) {
				return new \WP_Error( 'wawp_theme_download_failed', __( 'Téléchargement du thème WeAre[WP] impossible.', 'wawp-theme-updater' ) );
			}
			if ( ! Signature::verify_file( $file, $offer['signature'], $this->public_keys ) ) {
				wp_delete_file( $file );
				return new \WP_Error( 'wawp_theme_signature_invalid', __( 'Signature du thème WeAre[WP] invalide : mise à jour annulée.', 'wawp-theme-updater' ) );
			}
			// Paquet signé mais différent de l'offre (retour arrière ou substitution par un serveur compromis).
			$installed = $this->installed();
			if ( null === $installed || ! version_compare( $offer['version'], $installed, '>' ) || ! Package::matches( $file, $slug, $offer['version'] ) ) {
				wp_delete_file( $file );
				return new \WP_Error( 'wawp_theme_package_mismatch', __( 'Paquet du thème WeAre[WP] non conforme à la mise à jour proposée : mise à jour annulée.', 'wawp-theme-updater' ) );
			}

			return $file;
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'wawp_theme_updater_error', __( 'Erreur du client de mise à jour des thèmes WeAre[WP] : mise à jour annulée.', 'wawp-theme-updater' ) );
		}
	}

	/**
	 * Vide le cache du client.
	 */
	public function flush_cache(): void {
		try {
			delete_site_transient( Config::CACHE_KEY );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Vide le cache quand l'administrateur clique « Rechercher les mises à jour ».
	 */
	public function maybe_flush_on_force_check(): void {
		try {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lecture seule : isset() sans lire la valeur, vide uniquement un cache local.
			if ( isset( $_GET['force-check'] ) ) {
				$this->flush_cache();
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Avertit les administrateurs, sur l'écran Thèmes, d'une clé absente, d'un dossier mal nommé ou d'une licence refusée.
	 */
	public function admin_notice(): void {
		try {
			if ( ! current_user_can( 'update_themes' ) ) {
				return;
			}
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( null === $screen || ! in_array( $screen->id, array( 'themes', 'themes-network' ), true ) ) {
				return;
			}
			$message = null;
			if ( null === $this->key() ) {
				$message = __( 'WeAre[WP] : la constante WAWP_LICENSE_KEY est absente ou invalide dans wp-config.php. Les mises à jour du thème WeAre[WP] sont désactivées.', 'wawp-theme-updater' );
			} elseif ( null === $this->slug() ) {
				$message = __( 'WeAre[WP] : le nom du dossier du thème actif ne correspond pas à son slug (en-tête Text Domain de style.css). Réinstaller le thème à partir du zip publié pour réactiver ses mises à jour.', 'wawp-theme-updater' );
			} elseif ( $this->denied() ) {
				$message = __( 'WeAre[WP] : le serveur de mises à jour a refusé la licence de ce site. Vérifier la clé WAWP_LICENSE_KEY ou contacter WeAre[WP].', 'wawp-theme-updater' );
			}
			if ( null === $message ) {
				return;
			}
			printf( '<div class="notice notice-warning"><p>%s</p></div>', esc_html( $message ) );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Offres du serveur pour le thème inscrit, depuis le cache ou une requête.
	 *
	 * @param bool $fresh Ignorer le cache (jeton frais pour un téléchargement).
	 * @return array<string, array{version: string, requires: ?string, requires_php: ?string, url: string, package: string, signature: string}>
	 */
	private function offers( bool $fresh ): array {
		$key       = $this->key();
		$slug      = $this->slug();
		$installed = $this->installed();
		if ( null === $key || null === $slug || null === $installed ) {
			return array();
		}
		$site = $this->site();
		$hash = self::cache_hash( $key, $site, $slug, $installed );

		if ( ! $fresh ) {
			$cached = get_site_transient( Config::CACHE_KEY );
			if ( is_array( $cached ) && isset( $cached['hash'], $cached['body'] ) && $hash === $cached['hash'] && is_string( $cached['body'] ) ) {
				$offers = Theme_Validator::check_response( $cached['body'], array( $slug ) );
				if ( null !== $offers ) {
					return $offers;
				}
			}
		}

		$result = $this->client->check( $key, $site, array( $slug => $installed ) );
		$offers = $result['offers'];
		set_site_transient(
			Config::CACHE_KEY,
			array(
				'hash'   => $hash,
				'body'   => (string) wp_json_encode( array( 'themes' => (object) ( null === $offers ? array() : self::to_response( $offers ) ) ) ),
				'denied' => 401 === $result['status'],
			),
			null === $offers ? Config::ERROR_TTL : Config::CACHE_TTL
		);

		return null === $offers ? array() : $offers;
	}

	/**
	 * Offres validées remises au format de la réponse du serveur, pour le cache.
	 *
	 * @param array<string, array{version: string, requires: ?string, requires_php: ?string, url: string, package: string, signature: string}> $offers Offres.
	 * @return array<string, array<string, string|null>>
	 */
	private static function to_response( array $offers ): array {
		$themes = array();
		foreach ( $offers as $slug => $offer ) {
			$themes[ $slug ] = array(
				'theme'        => (string) $slug,
				'new_version'  => $offer['version'],
				'requires'     => $offer['requires'],
				'requires_php' => $offer['requires_php'],
				'url'          => $offer['url'],
				'package'      => $offer['package'],
				'signature'    => $offer['signature'],
			);
		}

		return $themes;
	}

	/**
	 * Empreinte du contexte de la requête mise en cache (la clé n'y figure que hachée).
	 *
	 * @param string $key       Clé de licence.
	 * @param string $site      Adresse du site.
	 * @param string $slug      Slug du thème.
	 * @param string $installed Version installée.
	 */
	private static function cache_hash( string $key, string $site, string $slug, string $installed ): string {
		return md5( (string) wp_json_encode( array( $site, $slug, $installed, Config::VERSION, hash( 'sha256', $key ) ) ) );
	}

	/**
	 * Vrai si la dernière réponse en cache, pour le contexte actuel, est un refus de licence (401).
	 */
	private function denied(): bool {
		$key       = $this->key();
		$slug      = $this->slug();
		$installed = $this->installed();
		if ( null === $key || null === $slug || null === $installed ) {
			return false;
		}
		$cached = get_site_transient( Config::CACHE_KEY );

		return is_array( $cached ) && isset( $cached['hash'] ) && self::cache_hash( $key, $this->site(), $slug, $installed ) === $cached['hash'] && true === ( $cached['denied'] ?? false );
	}

	/**
	 * Slug du thème, ou null si le nom du dossier n'est pas un slug valide ou diffère du Text Domain de style.css.
	 */
	private function slug(): ?string {
		if ( ! Validator::is_slug( $this->stylesheet ) ) {
			return null;
		}
		$data = get_file_data( $this->dir . '/style.css', array( 'TextDomain' => 'Text Domain' ) );

		return ( $data['TextDomain'] ?? '' ) === $this->stylesheet ? $this->stylesheet : null;
	}

	/**
	 * Version installée du thème, lue dans style.css, ou null si elle est illisible.
	 */
	private function installed(): ?string {
		$data    = get_file_data( $this->dir . '/style.css', array( 'Version' => 'Version' ) );
		$version = $data['Version'] ?? '';

		return Validator::is_installed_version( $version ) ? $version : null;
	}

	/**
	 * Clé de licence lue dans wp-config.php, ou null si absente ou invalide.
	 */
	private function key(): ?string {
		if ( ! defined( 'WAWP_LICENSE_KEY' ) ) {
			return null;
		}
		$key = constant( 'WAWP_LICENSE_KEY' );

		return Validator::is_key( $key ) ? $key : null;
	}

	/**
	 * Adresse du site envoyée au serveur (celle du réseau en multisite).
	 */
	private function site(): string {
		return is_multisite() ? network_home_url() : home_url();
	}
}
