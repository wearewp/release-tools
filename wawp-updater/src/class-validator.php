<?php
/**
 * Validation stricte des valeurs reçues du serveur et de la configuration.
 *
 * @package WAWP\Updater
 */

namespace WAWP\Updater;

defined( 'ABSPATH' ) || exit;

/**
 * Toute réponse du serveur est traitée comme non fiable : la moindre anomalie la fait rejeter entièrement.
 */
final class Validator {

	const SLUG              = '/^[a-z0-9-]{1,64}\z/';
	const VERSION           = '/^\d{1,5}(?:\.\d{1,5}){1,3}\z/';
	const INSTALLED_VERSION = '/^[0-9A-Za-z.+-]{1,32}\z/';
	const KEY               = '/^wawp_[A-Za-z0-9_-]{43}\z/';
	const DATE              = '/^\d{4}-\d{2}-\d{2}\z/';
	const TOKEN             = '/^[A-Za-z0-9_.-]{1,512}\z/';
	const NAME              = '/^[^\p{Cc}]{1,200}\z/u';
	const MAX_BODY          = 1048576;
	const MAX_SECTION       = 262144;

	/**
	 * @param mixed $value Valeur à tester.
	 * @phpstan-assert-if-true string $value
	 */
	public static function is_key( $value ): bool {
		return self::matches( self::KEY, $value );
	}

	/**
	 * @param mixed $value Valeur à tester.
	 */
	public static function is_slug( $value ): bool {
		return self::matches( self::SLUG, $value );
	}

	/**
	 * @param mixed $value Valeur à tester.
	 */
	public static function is_installed_version( $value ): bool {
		return self::matches( self::INSTALLED_VERSION, $value );
	}

	/**
	 * Valide le corps d'une réponse /v1/check.
	 *
	 * @param mixed    $body           Corps brut.
	 * @param string[] $expected_slugs Slugs envoyés au serveur.
	 * @return array<string, array{version: string, requires: ?string, tested: ?string, requires_php: ?string, last_updated: ?string, package: string, signature: string}>|null
	 */
	public static function check_response( $body, array $expected_slugs ): ?array {
		$data = self::decode( $body );
		if ( null === $data || ! isset( $data['plugins'] ) || ! is_array( $data['plugins'] ) ) {
			return null;
		}

		$offers = array();
		foreach ( $data['plugins'] as $slug => $offer ) {
			$slug = (string) $slug;
			if ( ! self::is_slug( $slug ) || ! in_array( $slug, $expected_slugs, true ) || ! is_array( $offer ) ) {
				return null;
			}
			$version      = $offer['version'] ?? null;
			$package      = $offer['package'] ?? null;
			$signature    = $offer['signature'] ?? null;
			$requires     = $offer['requires'] ?? null;
			$tested       = $offer['tested'] ?? null;
			$requires_php = $offer['requires_php'] ?? null;
			$last_updated = $offer['last_updated'] ?? null;
			if ( ! self::matches( self::VERSION, $version )
				|| ! self::is_package_url( $package )
				|| ! self::is_signature( $signature )
				|| ! self::optional( self::VERSION, $requires )
				|| ! self::optional( self::VERSION, $tested )
				|| ! self::optional( self::VERSION, $requires_php )
				|| ! self::optional( self::DATE, $last_updated ) ) {
				return null;
			}
			$offers[ $slug ] = array(
				'version'      => $version,
				'requires'     => $requires,
				'tested'       => $tested,
				'requires_php' => $requires_php,
				'last_updated' => $last_updated,
				'package'      => $package,
				'signature'    => $signature,
			);
		}

		return $offers;
	}

	/**
	 * Valide le corps d'une réponse /v1/info.
	 *
	 * @param mixed  $body Corps brut.
	 * @param string $slug Slug demandé.
	 * @return array{name: string, slug: string, version: string, requires: ?string, tested: ?string, requires_php: ?string, last_updated: ?string, sections: array{description: string, changelog: string}}|null
	 */
	public static function info_response( $body, string $slug ): ?array {
		$data = self::decode( $body );
		if ( null === $data ) {
			return null;
		}
		$name         = $data['name'] ?? null;
		$version      = $data['version'] ?? null;
		$requires     = $data['requires'] ?? null;
		$tested       = $data['tested'] ?? null;
		$requires_php = $data['requires_php'] ?? null;
		$last_updated = $data['last_updated'] ?? null;
		$sections     = $data['sections'] ?? null;
		if ( ! self::matches( self::NAME, $name )
			|| ( $data['slug'] ?? null ) !== $slug
			|| ! self::matches( self::VERSION, $version )
			|| ! self::optional( self::VERSION, $requires )
			|| ! self::optional( self::VERSION, $tested )
			|| ! self::optional( self::VERSION, $requires_php )
			|| ! self::optional( self::DATE, $last_updated )
			|| ! is_array( $sections ) ) {
			return null;
		}
		$description = $sections['description'] ?? null;
		$changelog   = $sections['changelog'] ?? null;
		if ( ! is_string( $description ) || ! is_string( $changelog ) || strlen( $description ) > self::MAX_SECTION || strlen( $changelog ) > self::MAX_SECTION ) {
			return null;
		}

		return array(
			'name'         => $name,
			'slug'         => $slug,
			'version'      => $version,
			'requires'     => $requires,
			'tested'       => $tested,
			'requires_php' => $requires_php,
			'last_updated' => $last_updated,
			'sections'     => array(
				'description' => $description,
				'changelog'   => $changelog,
			),
		);
	}

	/**
	 * @param mixed $body Corps brut.
	 * @return array<mixed>|null
	 */
	private static function decode( $body ): ?array {
		if ( ! is_string( $body ) || strlen( $body ) > self::MAX_BODY ) {
			return null;
		}
		$data = json_decode( $body, true, 8 );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * @param mixed $value Valeur à tester.
	 * @phpstan-assert-if-true string $value
	 */
	private static function is_package_url( $value ): bool {
		$prefix = Config::ENDPOINT . '/v1/download/';

		return is_string( $value )
			&& 0 === strpos( $value, $prefix )
			&& 1 === preg_match( self::TOKEN, (string) substr( $value, strlen( $prefix ) ) );
	}

	/**
	 * @param mixed $value Valeur à tester.
	 * @phpstan-assert-if-true string $value
	 */
	private static function is_signature( $value ): bool {
		if ( ! is_string( $value ) ) {
			return false;
		}
		$raw = base64_decode( $value, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- décodage d'une signature binaire.

		return false !== $raw && 64 === strlen( $raw );
	}

	/**
	 * @param string $pattern Motif.
	 * @param mixed  $value   Valeur à tester.
	 * @phpstan-assert-if-true string|null $value
	 */
	private static function optional( string $pattern, $value ): bool {
		return null === $value || self::matches( $pattern, $value );
	}

	/**
	 * @param string $pattern Motif.
	 * @param mixed  $value   Valeur à tester.
	 * @phpstan-assert-if-true string $value
	 */
	private static function matches( string $pattern, $value ): bool {
		return is_string( $value ) && 1 === preg_match( $pattern, $value );
	}
}
