<?php
/**
 * Public post types the plugin is willing to consider.
 *
 * @package AiReadyWp
 * @since   1.0.0
 */

namespace Fer\AiReady;

/**
 * Lists selectable post types.
 *
 * Attachments are public in WordPress but are files, not documents. Internal
 * `wp_*` template types are skipped when a build registers them as public.
 *
 * @since 1.0.0
 */
final class PostTypes {
	/**
	 * Selectable post types keyed by slug.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string> Slug => plural label.
	 */
	public static function selectable() {
		$objects = get_post_types( array( 'public' => true ), 'objects' );
		$out     = array();
		foreach ( $objects as $name => $object ) {
			if ( ! $object instanceof \WP_Post_Type ) {
				continue;
			}
			if ( 'attachment' === $name || 0 === strpos( $name, 'wp_' ) ) {
				continue;
			}
			$label        = isset( $object->labels->name ) ? (string) $object->labels->name : $name;
			$out[ $name ] = $label;
		}

		$filtered = apply_filters( 'ai_ready_wp_post_types', $out );
		if ( ! is_array( $filtered ) ) {
			return $out;
		}

		$clean = array();
		foreach ( $filtered as $slug => $label ) {
			$slug = is_string( $slug ) ? $slug : '';
			if ( '' === $slug || ! post_type_exists( $slug ) || 'attachment' === $slug ) {
				continue;
			}
			$clean[ $slug ] = is_string( $label ) && '' !== $label ? $label : $slug;
		}
		return $clean;
	}
}
