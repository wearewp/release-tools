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
	 * d'une panne, et une courte cause d'échec (null en cas de succès), sans la clé de licence.
	 *
	 * @param string                $key       Clé de licence.
	 * @param string                $site      Adresse du site.
	 * @param array<string, string> $installed Slug vers version installée.
	 * @return array{status: int, offers: array<string, array{version: string, requires: ?string, requires_php: ?string, url: string, package: string, signature: string}>|null, error: ?string}
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
				/* translators: %s: code et message de l'erreur de transport WordPress. */
				'error'  => sprintf( __( 'réseau : %s', 'wawp-theme-updater' ), self::plain( (string) $response->get_error_code(), $key ) . ' ' . self::plain( (string) $response->get_error_message(), $key ) ),
			);
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			return array(
				'status' => $status,
				'offers' => null,
				/* translators: %d: statut HTTP de la réponse du serveur. */
				'error'  => sprintf( __( 'HTTP %d', 'wawp-theme-updater' ), $status ),
			);
		}
		$offers = Theme_Validator::check_response( wp_remote_retrieve_body( $response ), array_map( 'strval', array_keys( $installed ) ) );

		return array(
			'status' => 200,
			'offers' => $offers,
			'error'  => null === $offers ? __( 'réponse invalide', 'wawp-theme-updater' ) : null,
		);
	}

	/**
	 * Texte d'une erreur de transport (code ou message) réduit à du texte brut d'une ligne : clé de licence masquée,
	 * tronqué à 200 caractères. La requête /v1/check ne porte aucun jeton de téléchargement.
	 *
	 * @param string $text Code ou message de l'erreur.
	 * @param string $key  Clé de licence.
	 */
	private static function plain( string $text, string $key ): string {
		$text = (string) preg_replace( '/[\x00-\x1F\x7F<>]/', ' ', str_replace( $key, '***', $text ) );

		// mb_substr() est toujours défini : WordPress en fournit une version de repli (wp-includes/compat.php).
		return mb_substr( $text, 0, 200, 'UTF-8' );
	}
}
