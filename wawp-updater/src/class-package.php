<?php
/**
 * Contrôle du contenu d'un paquet téléchargé.
 *
 * @package WAWP\Updater
 */

namespace WAWP\Updater;

defined( 'ABSPATH' ) || exit;

/**
 * Vérifie qu'un paquet signé correspond bien à la mise à jour proposée (slug et version), pour refuser
 * un retour arrière ou une substitution par un serveur compromis. Lecture seule, aucune extraction sur disque.
 */
final class Package {

	/** Taille lue pour les en-têtes du fichier principal, comme WordPress. */
	const HEADER_BYTES = 8192;

	/**
	 * Toutes les entrées sont sous `<slug>/` et l'en-tête Version de `<slug>/<slug>.php` vaut `$version`.
	 *
	 * @param string $file    Archive zip téléchargée.
	 * @param string $slug    Slug du plugin.
	 * @param string $version Version de l'offre.
	 */
	public static function matches( string $file, string $slug, string $version ): bool {
		try {
			if ( ! Validator::is_slug( $slug ) || '' === $version || ! is_file( $file ) ) {
				return false;
			}
			$archive = self::read( $file, $slug . '/' . $slug . '.php' );
			if ( null === $archive ) {
				return false;
			}
			foreach ( $archive['entries'] as $entry ) {
				if ( 0 !== strpos( $entry, $slug . '/' ) ) {
					return false;
				}
			}

			return self::header( $archive['main'], 'Version' ) === $version;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Liste des entrées et début du fichier principal, par ZipArchive ou, à défaut, par PclZip du cœur.
	 *
	 * @param string $file Archive zip.
	 * @param string $main Chemin du fichier principal dans l'archive.
	 * @return array{entries: list<string>, main: string}|null Null si l'archive est illisible ou le fichier absent.
	 */
	private static function read( string $file, string $main ): ?array {
		if ( class_exists( 'ZipArchive' ) ) {
			$zip = new \ZipArchive();
			if ( true !== $zip->open( $file ) ) {
				return null;
			}
			$entries = array();
			$count   = $zip->count();
			for ( $i = 0; $i < $count; $i++ ) {
				$name = $zip->getNameIndex( $i );
				if ( false === $name ) {
					$zip->close();
					return null;
				}
				$entries[] = $name;
			}
			$content = $zip->getFromName( $main, self::HEADER_BYTES );
			$zip->close();

			return false === $content ? null : array(
				'entries' => $entries,
				'main'    => $content,
			);
		}

		if ( ! class_exists( 'PclZip' ) ) {
			$pclzip = ABSPATH . 'wp-admin/includes/class-pclzip.php';
			if ( ! is_readable( $pclzip ) ) {
				return null;
			}
			require_once $pclzip;
		}
		$archive = new \PclZip( $file );
		$list    = $archive->listContent();
		if ( ! is_array( $list ) ) {
			return null;
		}
		$entries = array();
		foreach ( $list as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['filename'] ) || ! is_string( $item['filename'] ) ) {
				return null;
			}
			$entries[] = $item['filename'];
		}
		$extracted = self::call( array( $archive, 'extract' ), array( PCLZIP_OPT_BY_NAME, $main, PCLZIP_OPT_EXTRACT_AS_STRING ) );
		if ( ! is_array( $extracted ) || ! isset( $extracted[0] ) || ! is_array( $extracted[0] ) || ! isset( $extracted[0]['content'] ) || ! is_string( $extracted[0]['content'] ) ) {
			return null;
		}

		return array(
			'entries' => $entries,
			'main'    => substr( $extracted[0]['content'], 0, self::HEADER_BYTES ),
		);
	}

	/**
	 * Appel d'une méthode à options variadiques : PclZip lit ses options par func_get_args(), alors que les
	 * stubs WordPress déclarent extract() sans paramètre.
	 *
	 * @param callable     $method    Méthode à appeler.
	 * @param list<mixed>  $arguments Arguments.
	 * @return mixed
	 */
	private static function call( callable $method, array $arguments ) {
		return call_user_func_array( $method, $arguments );
	}

	/**
	 * Valeur d'un en-tête, extraite comme get_file_data() de WordPress.
	 *
	 * @param string $content Début du fichier.
	 * @param string $name    Nom de l'en-tête.
	 */
	private static function header( string $content, string $name ): ?string {
		$content = str_replace( "\r", "\n", $content );
		if ( 1 !== preg_match( '/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote( $name, '/' ) . ':(.*)$/mi', $content, $match ) ) {
			return null;
		}
		$value = trim( (string) preg_replace( '/\s*(?:\*\/|\?>).*/', '', $match[1] ) );

		return '' === $value ? null : $value;
	}
}
