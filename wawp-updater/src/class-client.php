<?php
/**
 * Appels HTTP au serveur de mises à jour.
 *
 * @package WAWP\Updater
 */

namespace WAWP\Updater;

defined( 'ABSPATH' ) || exit;

/**
 * Seules les fonctions HTTP « safe » de WordPress sont utilisées, sans redirection, TLS vérifié.
 */
final class Client {

	/**
	 * Interroge /v1/check pour tous les plugins inscrits, en une requête.
	 *
	 * @param string                $key       Clé de licence.
	 * @param string                $site      Adresse du site.
	 * @param array<string, string> $installed Slug vers version installée.
	 * @return array<string, array{version: string, requires: ?string, tested: ?string, requires_php: ?string, last_updated: ?string, package: string, signature: string}>|null
	 */
	public function check( string $key, string $site, array $installed ): ?array {
		$response = wp_safe_remote_post(
			Config::ENDPOINT . '/v1/check',
			array(
				'timeout'     => Config::TIMEOUT,
				'redirection' => 0,
				'sslverify'   => true,
				'user-agent'  => 'wawp-updater/' . Config::VERSION . '; ' . $site,
				'headers'     => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
				),
				'body'        => (string) wp_json_encode(
					array(
						'site'    => $site,
						'plugins' => (object) $installed,
						'wp'      => (string) get_bloginfo( 'version' ),
						'php'     => PHP_VERSION,
						'client'  => Config::VERSION,
					)
				),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		return Validator::check_response( wp_remote_retrieve_body( $response ), array_map( 'strval', array_keys( $installed ) ) );
	}

	/**
	 * Interroge /v1/info pour la fenêtre « Afficher les détails ».
	 *
	 * @param string $key  Clé de licence.
	 * @param string $site Adresse du site.
	 * @param string $slug Slug du plugin.
	 * @return array{name: string, slug: string, version: string, requires: ?string, tested: ?string, requires_php: ?string, last_updated: ?string, sections: array{description: string, changelog: string}}|null
	 */
	public function info( string $key, string $site, string $slug ): ?array {
		$response = wp_safe_remote_get(
			Config::ENDPOINT . '/v1/info/' . rawurlencode( $slug ),
			array(
				'timeout'     => Config::TIMEOUT,
				'redirection' => 0,
				'sslverify'   => true,
				'user-agent'  => 'wawp-updater/' . Config::VERSION . '; ' . $site,
				'headers'     => array(
					'Authorization' => 'Bearer ' . $key,
					'X-WAWP-Site'   => $site,
				),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		return Validator::info_response( wp_remote_retrieve_body( $response ), $slug );
	}
}
