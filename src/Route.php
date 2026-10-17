<?php
/**
 * Decide whether a public request belongs to this plugin.
 *
 * @package AiReady
 */

namespace AiReady;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure routing decision. A catch-all rewrite would claim every .md URL.
 */
final class Route {

	public const FALL  = 'fall';
	public const MISS  = 'miss';
	public const INDEX = 'index';
	public const FULL  = 'full';
	public const DOC   = 'doc';

	/**
	 * @return array{action: string, id: int}
	 */
	public static function decide( string $path, bool $resolved, bool $included, int $id ): array {
		$path = trim( $path, '/' );
		if ( 'llms.txt' === $path ) {
			return self::result( self::INDEX, 0 );
		}
		if ( 'llms-full.txt' === $path ) {
			return self::result( self::FULL, 0 );
		}
		if ( strlen( $path ) >= 3 && '.md' === substr( $path, -3 ) ) {
			if ( $included && $id > 0 ) {
				return self::result( self::DOC, $id );
			}
			if ( $resolved ) {
				return self::result( self::MISS, 0 );
			}
			return self::result( self::FALL, 0 );
		}
		return self::result( self::FALL, 0 );
	}

	/**
	 * @return array{action: string, id: int}
	 */
	public static function decide_format( bool $asked, bool $singular, bool $resolved, bool $included, int $id ): array {
		if ( ! $asked || ! $singular ) {
			return self::result( self::FALL, 0 );
		}
		if ( $included && $id > 0 ) {
			return self::result( self::DOC, $id );
		}
		if ( $resolved ) {
			return self::result( self::MISS, 0 );
		}
		return self::result( self::FALL, 0 );
	}

	/**
	 * @return array{action: string, id: int}
	 */
	private static function result( string $action, int $id ): array {
		return array(
			'action' => $action,
			'id'     => $id,
		);
	}
}
