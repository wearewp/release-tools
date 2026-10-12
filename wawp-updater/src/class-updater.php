<?php
/**
 * Points d'accroche WordPress du client de mise à jour.
 *
 * @package WAWP\Updater
 */

namespace WAWP\Updater;

defined( 'ABSPATH' ) || exit;

/**
 * Gère tous les plugins WeAre[WP] inscrits sur le site. Aucune erreur ne doit interrompre WordPress.
 */
final class Updater {

	/**
	 * Slug vers fichier principal.
	 *
	 * @var array<string, string>
	 */
	private $files = array();

	/**
	 * Nom de base WordPress (slug/slug.php) vers slug.
	 *
	 * @var array<string, string>
	 */
	private $basenames = array();

	/**
	 * @var Client
	 */
	private $client;

	/**
	 * @var string[]
	 */
	private $public_keys;

	/**
	 * @param string[] $plugin_files Fichiers principaux inscrits.
	 * @param Client   $client       Client HTTP.
	 * @param string[] $public_keys  Clés publiques Ed25519 en base64.
	 */
	public function __construct( array $plugin_files, Client $client, array $public_keys ) {
		$this->client      = $client;
		$this->public_keys = $public_keys;
		foreach ( $plugin_files as $file ) {
			$basename = plugin_basename( (string) $file );
			$slug     = dirname( $basename );
			if ( Validator::is_slug( $slug ) && $slug . '/' . $slug . '.php' === $basename ) {
				$this->files[ $slug ]         = (string) $file;
				$this->basenames[ $basename ] = $slug;
			}
		}
	}

	/**
	 * Enregistre les points d'accroche.
	 */
	public function hooks(): void {
		add_filter( 'update_plugins_' . Config::HOST, array( $this, 'filter_update' ), 10, 4 );
		add_filter( 'plugins_api', array( $this, 'filter_plugins_api' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( $this, 'filter_pre_download' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'flush_cache' ), 10, 0 );
		add_action( 'load-update-core.php', array( $this, 'maybe_flush_on_force_check' ), 9 );
		add_action( 'admin_notices', array( $this, 'admin_notice' ) );
		add_action( 'network_admin_notices', array( $this, 'admin_notice' ) );
		add_action( 'admin_init', array( $this, 'cleanup_legacy' ) );
	}

	/**
	 * Filtre update_plugins_{hôte} : données de mise à jour d'un plugin inscrit.
	 *
	 * @param mixed $update      Valeur reçue.
	 * @param mixed $plugin_data En-têtes du plugin.
	 * @param mixed $plugin_file Nom de base du plugin.
	 * @param mixed $locales     Langues installées.
	 * @return mixed
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature imposée par le filtre update_plugins_{hôte} (4 arguments).
	public function filter_update( $update, $plugin_data, $plugin_file, $locales ) {
		try {
			$slug = is_string( $plugin_file ) ? ( $this->basenames[ $plugin_file ] ?? null ) : null;
			if ( null === $slug ) {
				return $update;
			}
			$offers = $this->offers( false );
			if ( ! isset( $offers[ $slug ] ) ) {
				return $update;
			}
			$offer = $offers[ $slug ];

			return array_filter(
				array(
					'slug'         => $slug,
					'version'      => $offer['version'],
					'url'          => Config::ENDPOINT,
					'package'      => $offer['package'],
					'requires'     => $offer['requires'],
					'tested'       => $offer['tested'],
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
	 * Filtre plugins_api : fenêtre « Afficher les détails ».
	 *
	 * @param mixed $result Valeur reçue.
	 * @param mixed $action Action demandée.
	 * @param mixed $args   Arguments de la requête.
	 * @return mixed
	 */
	public function filter_plugins_api( $result, $action, $args ) {
		$unavailable = new \WP_Error( 'wawp_info_unavailable', __( 'Détails de l\'extension WeAre[WP] indisponibles : licence absente ou serveur injoignable.', 'wawp-updater' ) );
		try {
			if ( 'plugin_information' !== $action || ! is_object( $args ) || ! isset( $args->slug ) || ! is_string( $args->slug ) || ! isset( $this->files[ $args->slug ] ) ) {
				return $result;
			}
			$key = $this->key();
			if ( null === $key ) {
				return $unavailable;
			}
			$slug      = $args->slug;
			$cache_key = Config::INFO_CACHE_PREFIX . $slug;
			$cached    = get_site_transient( $cache_key );
			$info      = is_string( $cached ) ? Validator::info_response( $cached, $slug ) : null;
			if ( null === $info ) {
				$info = $this->client->info( $key, $this->site(), $slug );
				if ( null === $info ) {
					return $unavailable;
				}
				set_site_transient( $cache_key, (string) wp_json_encode( $info ), Config::CACHE_TTL );
			}

			return (object) array(
				'name'          => wp_strip_all_tags( $info['name'] ),
				'slug'          => $slug,
				'version'       => $info['version'],
				'requires'      => $info['requires'],
				'tested'        => $info['tested'],
				'requires_php'  => $info['requires_php'],
				'last_updated'  => $info['last_updated'],
				'download_link' => '',
				'external'      => true,
				'sections'      => array(
					'description' => wp_kses_post( $info['sections']['description'] ),
					'changelog'   => wp_kses_post( $info['sections']['changelog'] ),
				),
			);
		} catch ( \Throwable $e ) {
			return is_object( $args ) && isset( $args->slug ) && is_string( $args->slug ) && isset( $this->files[ $args->slug ] ) ? $unavailable : $result;
		}
	}

	/**
	 * Filtre upgrader_pre_download : jeton frais, téléchargement, vérification de la signature.
	 *
	 * @param mixed $reply      Valeur reçue (false si personne n'a encore traité le paquet).
	 * @param mixed $package    URL du paquet.
	 * @param mixed $upgrader   Instance de WP_Upgrader.
	 * @param mixed $hook_extra Contexte de la mise à jour.
	 * @return mixed Chemin du fichier vérifié, WP_Error, ou la valeur reçue.
	 */
	public function filter_pre_download( $reply, $package, $upgrader, $hook_extra ) {
		if ( false !== $reply || ! is_string( $package ) || 0 !== strpos( $package, Config::ENDPOINT . '/v1/download/' ) ) {
			return $reply;
		}
		try {
			$plugin = is_array( $hook_extra ) && isset( $hook_extra['plugin'] ) && is_string( $hook_extra['plugin'] ) ? $hook_extra['plugin'] : '';
			$slug   = $this->basenames[ $plugin ] ?? null;
			if ( null === $slug ) {
				return new \WP_Error( 'wawp_unknown_package', __( 'Paquet WeAre[WP] inconnu : mise à jour annulée.', 'wawp-updater' ) );
			}
			$offers = $this->offers( true );
			if ( ! isset( $offers[ $slug ] ) ) {
				return new \WP_Error( 'wawp_token_unavailable', __( 'Mise à jour WeAre[WP] indisponible : licence refusée ou serveur injoignable.', 'wawp-updater' ) );
			}
			$file = download_url( $offers[ $slug ]['package'], Config::DOWNLOAD_TIMEOUT );
			if ( is_wp_error( $file ) ) {
				return new \WP_Error( 'wawp_download_failed', __( 'Téléchargement du paquet WeAre[WP] impossible.', 'wawp-updater' ) );
			}
			if ( ! Signature::verify_file( $file, $offers[ $slug ]['signature'], $this->public_keys ) ) {
				wp_delete_file( $file );
				return new \WP_Error( 'wawp_signature_invalid', __( 'Signature du paquet WeAre[WP] invalide : mise à jour annulée.', 'wawp-updater' ) );
			}

			return $file;
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'wawp_updater_error', __( 'Erreur du client de mise à jour WeAre[WP] : mise à jour annulée.', 'wawp-updater' ) );
		}
	}

	/**
	 * Vide les caches du client.
	 */
	public function flush_cache(): void {
		try {
			delete_site_transient( Config::CACHE_KEY );
			foreach ( array_keys( $this->files ) as $slug ) {
				delete_site_transient( Config::INFO_CACHE_PREFIX . $slug );
			}
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
	 * Avertit les administrateurs, sur l'écran Extensions, si la clé est absente ou invalide.
	 */
	public function admin_notice(): void {
		try {
			if ( array() === $this->files || ! current_user_can( 'update_plugins' ) || null !== $this->key() ) {
				return;
			}
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( null === $screen || ! in_array( $screen->id, array( 'plugins', 'plugins-network' ), true ) ) {
				return;
			}
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'WeAre[WP] : la constante WAWP_LICENSE_KEY est absente ou invalide dans wp-config.php. Les mises à jour des extensions WeAre[WP] sont désactivées.', 'wawp-updater' )
			);
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Supprime une fois le cache laissé par Plugin Update Checker (migration).
	 */
	public function cleanup_legacy(): void {
		try {
			$flag = md5( implode( ',', array_keys( $this->files ) ) );
			if ( get_site_option( 'wawp_updater_cleanup' ) === $flag ) {
				return;
			}
			foreach ( array_keys( $this->files ) as $slug ) {
				delete_option( 'external_updates-' . $slug );
				delete_site_option( 'external_updates-' . $slug );
			}
			update_site_option( 'wawp_updater_cleanup', $flag );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Offres du serveur pour les plugins inscrits, depuis le cache ou une requête.
	 *
	 * @param bool $fresh Ignorer le cache (jeton frais pour un téléchargement).
	 * @return array<string, array{version: string, requires: ?string, tested: ?string, requires_php: ?string, last_updated: ?string, package: string, signature: string}>
	 */
	private function offers( bool $fresh ): array {
		$key = $this->key();
		if ( null === $key ) {
			return array();
		}
		$installed = $this->installed();
		if ( array() === $installed ) {
			return array();
		}
		$site  = $this->site();
		$slugs = array_map( 'strval', array_keys( $installed ) );
		$hash  = md5( (string) wp_json_encode( array( $site, $installed, Config::VERSION, hash( 'sha256', $key ) ) ) );

		if ( ! $fresh ) {
			$cached = get_site_transient( Config::CACHE_KEY );
			if ( is_array( $cached ) && isset( $cached['hash'], $cached['body'] ) && $hash === $cached['hash'] && is_string( $cached['body'] ) ) {
				$offers = Validator::check_response( $cached['body'], $slugs );
				if ( null !== $offers ) {
					return $offers;
				}
			}
		}

		$offers = $this->client->check( $key, $site, $installed );
		set_site_transient(
			Config::CACHE_KEY,
			array(
				'hash' => $hash,
				'body' => (string) wp_json_encode( array( 'plugins' => (object) ( null === $offers ? array() : $offers ) ) ),
			),
			null === $offers ? Config::ERROR_TTL : Config::CACHE_TTL
		);

		return null === $offers ? array() : $offers;
	}

	/**
	 * Versions installées des plugins inscrits.
	 *
	 * @return array<string, string>
	 */
	private function installed(): array {
		$installed = array();
		foreach ( $this->files as $slug => $file ) {
			$data    = get_file_data( $file, array( 'Version' => 'Version' ) );
			$version = $data['Version'] ?? '';
			if ( Validator::is_installed_version( $version ) ) {
				$installed[ $slug ] = $version;
			}
		}

		return $installed;
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
