<?php
/**
 * Pure rules for whether a document may be published.
 *
 * @package AiReadyWp
 * @since   1.0.0
 */

namespace Fer\AiReady\Domain;

/**
 * Decides membership from facts collected at the WordPress boundary.
 *
 * A document is public when it is published, has no password, uses a selected
 * type, is not a reserved screen, is not excluded, and is not noindex.
 *
 * @since 1.0.0
 */
final class Membership {
	/**
	 * Whether the document may have a Markdown URL and an index link.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $facts status, password, selected, reserved, excluded, noindex.
	 * @return bool
	 */
	public static function keeps( array $facts ) {
		return self::structural( $facts ) && empty( $facts['excluded'] ) && empty( $facts['noindex'] );
	}

	/**
	 * Status, password, type, and reserved-screen checks.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $facts Facts.
	 * @return bool
	 */
	public static function structural( array $facts ) {
		$status = isset( $facts['status'] ) ? (string) $facts['status'] : '';
		if ( 'publish' !== $status || ! empty( $facts['password'] ) ) {
			return false;
		}
		if ( empty( $facts['selected'] ) || ! empty( $facts['reserved'] ) ) {
			return false;
		}
		return true;
	}
}
