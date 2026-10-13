<?php
/**
 * Validation stricte de la réponse /v1/check pour les thèmes.
 *
 * @package WAWP\ThemeUpdater
 */

namespace WAWP\ThemeUpdater;

defined( 'ABSPATH' ) || exit;

/**
 * Contrôle ce qui est propre aux thèmes (clé « themes », champ « theme », adresse « url »), puis délègue tous les
 * contrôles communs (version, adresse de paquet, signature, versions requises) à Validator, copie conforme de celle
 * de wawp-updater. La moindre anomalie fait rejeter la réponse entière.
 */
final class Theme_Validator {

	/** Adresse du thème (Theme URI) : http ou https, sans espace, guillemet, chevron, barre oblique inverse ni caractère de contrôle. */
	const URL = '/^https?:\/\/[^\s"<>\\\\\p{Cc}]{1,2040}\z/u';

	/**
	 * Valide le corps d'une réponse /v1/check limitée aux thèmes.
	 *
	 * @param mixed    $body           Corps brut.
	 * @param string[] $expected_slugs Slugs envoyés au serveur.
	 * @return array<string, array{version: string, requires: ?string, requires_php: ?string, url: string, package: string, signature: string}>|null
	 */
	public static function check_response( $body, array $expected_slugs ): ?array {
		if ( ! is_string( $body ) || strlen( $body ) > Validator::MAX_BODY ) {
			return null;
		}
		$data = json_decode( $body, true, 8 );
		if ( ! is_array( $data ) || ! isset( $data['themes'] ) || ! is_array( $data['themes'] ) ) {
			return null;
		}

		$plugins = array();
		$urls    = array();
		foreach ( $data['themes'] as $slug => $offer ) {
			$slug = (string) $slug;
			if ( ! Validator::is_slug( $slug ) || ! in_array( $slug, $expected_slugs, true ) || ! is_array( $offer ) ) {
				return null;
			}
			$url = $offer['url'] ?? null;
			if ( ( $offer['theme'] ?? null ) !== $slug || ! is_string( $url ) || ( '' !== $url && 1 !== preg_match( self::URL, $url ) ) ) {
				return null;
			}
			$plugins[ $slug ] = array(
				'version'      => $offer['new_version'] ?? null,
				'requires'     => $offer['requires'] ?? null,
				'requires_php' => $offer['requires_php'] ?? null,
				'package'      => $offer['package'] ?? null,
				'signature'    => $offer['signature'] ?? null,
			);
			$urls[ $slug ]    = $url;
		}

		// Contrôles communs avec les plugins, sur la même structure que leur réponse.
		$checked = Validator::check_response( (string) wp_json_encode( array( 'plugins' => (object) $plugins ) ), $expected_slugs );
		if ( null === $checked ) {
			return null;
		}

		$offers = array();
		foreach ( $checked as $slug => $offer ) {
			$offers[ $slug ] = array(
				'version'      => $offer['version'],
				'requires'     => $offer['requires'],
				'requires_php' => $offer['requires_php'],
				'url'          => $urls[ $slug ] ?? '',
				'package'      => $offer['package'],
				'signature'    => $offer['signature'],
			);
		}

		return $offers;
	}
}
