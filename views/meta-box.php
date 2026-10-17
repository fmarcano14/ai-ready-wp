<?php
/**
 * Per-document exclude box.
 *
 * Variables are prepared by the controller. This file only escapes them.
 *
 * @package AiReadyWp
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<p>
	<input type="hidden" name="ai_ready_wp_exclude" value="0" />
	<label>
		<input type="checkbox" name="ai_ready_wp_exclude" value="1" <?php checked( $excluded ); ?> />
		<?php esc_html_e( 'Exclude this document from AI Ready', 'ai-ready-wp' ); ?>
	</label>
</p>
<p>
	<strong><?php esc_html_e( 'Effective state:', 'ai-ready-wp' ); ?></strong>
	<?php echo esc_html( $effective_label ); ?>
</p>
<p class="description"><?php echo esc_html( $explanation ); ?></p>
<?php if ( '' !== $markdown_url ) : ?>
	<p><a href="<?php echo esc_url( $markdown_url ); ?>"><?php esc_html_e( 'View Markdown', 'ai-ready-wp' ); ?></a></p>
<?php endif; ?>
