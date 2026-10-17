<?php
/**
 * Which posts may appear in public AI files.
 *
 * @package AiReady
 */

namespace AiReady;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure inclusion rule.
 */
final class Inclusion {

	/**
	 * @param string[] $enabled_types Post types the site owner turned on.
	 */
	public static function allowed( PostFacts $facts, array $enabled_types ): bool {
		if ( ! $facts->public_type() ) {
			return false;
		}
		if ( 'publish' !== $facts->status() ) {
			return false;
		}
		if ( $facts->has_password() ) {
			return false;
		}
		if ( $facts->excluded() ) {
			return false;
		}
		if ( $facts->noindex() ) {
			return false;
		}
		return in_array( $facts->type(), $enabled_types, true );
	}
}
