<?php
/**
 * Vérification des signatures Ed25519 des paquets.
 *
 * @package WAWP\ThemeUpdater
 */

namespace WAWP\ThemeUpdater;

defined( 'ABSPATH' ) || exit;

/**
 * Même mécanisme que WordPress pour ses propres paquets : Ed25519 détachée sur le SHA-384 binaire du fichier.
 */
final class Signature {

	/**
	 * @param string   $path            Fichier téléchargé.
	 * @param string   $signature_b64   Signature en base64.
	 * @param string[] $public_keys_b64 Clés publiques en base64.
	 */
	public static function verify_file( string $path, string $signature_b64, array $public_keys_b64 ): bool {
		$signature = base64_decode( trim( $signature_b64 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- signature binaire.
		if ( false === $signature || 64 !== strlen( $signature ) || ! is_file( $path ) ) {
			return false;
		}
		$digest = hash_file( 'sha384', $path, true );
		if ( false === $digest ) {
			return false;
		}

		foreach ( $public_keys_b64 as $public_key_b64 ) {
			$public_key = base64_decode( (string) $public_key_b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- clé publique binaire.
			if ( false === $public_key || 32 !== strlen( $public_key ) ) {
				continue;
			}
			try {
				if ( sodium_crypto_sign_verify_detached( $signature, $digest, $public_key ) ) {
					return true;
				}
			} catch ( \Throwable $e ) {
				continue;
			}
		}

		return false;
	}
}
