<?php
/**
 * Appel HTTP au serveur de mises à jour pour le thème actif.
 *
 * @package WAWP\ThemeUpdater
 */

namespace WAWP\ThemeUpdater;

defined( 'ABSPATH' ) || exit;

/**
 * Seules les fonctions HTTP « safe » de WordPress sont utilisées, sans redirection, TLS vérifié.
 */
final class Client {

	/**
	 * Interroge /v1/check pour le thème inscrit. Le statut HTTP est rendu pour distinguer un refus de licence (401)
	 * d'une panne.
	 *
	 * @param string                $key       Clé de licence.
	 * @param string                $site      Adresse du site.
	 * @param array<string, string> $installed Slug vers version installée.
	 * @return array{status: int, offers: array<string, array{version: string, requires: ?string, requires_php: ?string, url: string, package: string, signature: string}>|null}
	 */
	public function check( string $key, string $site, array $installed ): array {
		$response = wp_safe_remote_post(
			Config::ENDPOINT . '/v1/check',
			array(
				'timeout'     => Config::TIMEOUT,
				'redirection' => 0,
				'sslverify'   => true,
				'user-agent'  => 'wawp-theme-updater/' . Config::VERSION . '; ' . $site,
				'headers'     => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
				),
				'body'        => (string) wp_json_encode(
					array(
						'site'   => $site,
						'themes' => (object) $installed,
						'wp'     => (string) get_bloginfo( 'version' ),
						'php'    => PHP_VERSION,
						'client' => Config::VERSION,
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'status' => 0,
				'offers' => null,
			);
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			return array(
				'status' => $status,
				'offers' => null,
			);
		}

		return array(
			'status' => 200,
			'offers' => Theme_Validator::check_response( wp_remote_retrieve_body( $response ), array_map( 'strval', array_keys( $installed ) ) ),
		);
	}
}
