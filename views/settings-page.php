<?php
/**
 * Settings screen markup.
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
<div class="wrap ai-ready-wp">
	<header class="ai-ready-wp__header">
		<div>
			<h1><?php esc_html_e( 'AI Ready', 'ai-ready-wp' ); ?></h1>
			<p class="ai-ready-wp__lede"><?php esc_html_e( 'Publish /llms.txt and a clean Markdown version of every public document. Generation stays on this site.', 'ai-ready-wp' ); ?></p>
		</div>
		<p class="ai-ready-wp__credit">
			<?php
			echo wp_kses(
				sprintf(
					/* translators: %s: linked organization name. */
					__( 'Fernando Marcano, %s', 'ai-ready-wp' ),
					'<a href="' . esc_url( 'https://fer.marketing' ) . '">Fer LLC</a>'
				),
				array(
					'a' => array( 'href' => array() ),
				)
			);
			?>
		</p>
	</header>

	<section class="ai-ready-wp__status" aria-label="<?php esc_attr_e( 'Status', 'ai-ready-wp' ); ?>">
		<ul class="ai-ready-wp__chips">
			<li>
				<?php echo esc_html( $status['discovery'] ? __( 'Discovery on', 'ai-ready-wp' ) : __( 'Discovery off', 'ai-ready-wp' ) ); ?>
			</li>
			<li>
				<?php echo esc_html( ! empty( $settings['enable_markdown'] ) ? __( 'Markdown on', 'ai-ready-wp' ) : __( 'Markdown off', 'ai-ready-wp' ) ); ?>
			</li>
			<li>
				<?php
				echo esc_html(
					$status['generated_at']
						? sprintf(
							/* translators: %s: localized date and time. */
							__( 'Generated %s', 'ai-ready-wp' ),
							wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $status['generated_at'] )
						)
						: __( 'Not generated yet', 'ai-ready-wp' )
				);
				?>
			</li>
		</ul>
		<p class="ai-ready-wp__status-copy">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: document count, 2: skipped count. */
					__( '%1$d documents in the last index. %2$d candidates were skipped.', 'ai-ready-wp' ),
					(int) $status['total'],
					(int) $status['skipped']
				)
			);
			?>
			<?php if ( $status['next_cron'] ) : ?>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: localized date and time. */
						__( 'Next rebuild: %s.', 'ai-ready-wp' ),
						wp_date( get_option( 'time_format' ), (int) $status['next_cron'] )
					)
				);
				?>
			<?php endif; ?>
		</p>
		<p class="ai-ready-wp__links">
			<a href="<?php echo esc_url( $status['llms_url'] ); ?>"><?php esc_html_e( 'Open llms.txt', 'ai-ready-wp' ); ?></a>
			<?php if ( ! empty( $settings['enable_llms_full'] ) ) : ?>
				<a href="<?php echo esc_url( $status['full_url'] ); ?>"><?php esc_html_e( 'Open llms-full.txt', 'ai-ready-wp' ); ?></a>
			<?php endif; ?>
		</p>
		<?php if ( 'enabled' === $status['mode'] && ! $status['blog_public'] ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'Discovery is forced on while WordPress is discouraging search engines. llms.txt stays public. It does not change robots.txt.', 'ai-ready-wp' ); ?></p></div>
		<?php endif; ?>
		<?php if ( '' !== $status['last_error'] ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( $status['last_error'] ); ?></p></div>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ai-ready-wp__generate">
			<input type="hidden" name="action" value="ai_ready_wp_generate" />
			<?php wp_nonce_field( 'ai_ready_wp_generate' ); ?>
			<?php submit_button( __( 'Generate now', 'ai-ready-wp' ), 'secondary', 'submit', false ); ?>
		</form>
	</section>

	<div class="ai-ready-wp__layout">
		<form id="ai-ready-wp-settings" method="post" action="options.php" class="ai-ready-wp__form">
			<?php settings_fields( 'ai_ready_wp' ); ?>
			<input type="hidden" name="<?php echo esc_attr( $option_name ); ?>[post_types_present]" value="1" />

			<fieldset class="ai-ready-wp__card">
				<legend><?php esc_html_e( 'Output', 'ai-ready-wp' ); ?></legend>
				<p>
					<label for="ai-ready-discovery"><?php esc_html_e( 'llms.txt discovery', 'ai-ready-wp' ); ?></label><br />
					<select id="ai-ready-discovery" name="<?php echo esc_attr( $option_name ); ?>[discovery_mode]">
						<?php
						$modes = array(
							'follow'   => __( 'Follow WordPress visibility', 'ai-ready-wp' ),
							'enabled'  => __( 'Always publish', 'ai-ready-wp' ),
							'disabled' => __( 'Disabled', 'ai-ready-wp' ),
						);
						foreach ( $modes as $value => $label ) :
							?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['discovery_mode'], $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p class="description"><?php esc_html_e( 'Follow hides /llms.txt and the describedby link while Settings → Reading discourages search engines. Document Markdown is controlled separately.', 'ai-ready-wp' ); ?></p>
				<?php
				$checks = array(
					'enable_llms_full'  => __( 'Also publish /llms-full.txt', 'ai-ready-wp' ),
					'enable_markdown'   => __( 'Publish a Markdown version of each document', 'ai-ready-wp' ),
					'enable_head_links' => __( 'Advertise the files with link elements', 'ai-ready-wp' ),
					'markdown_links'    => __( 'Point llms.txt links at the Markdown URLs', 'ai-ready-wp' ),
					'link_notes'        => __( 'Add a short note after each link', 'ai-ready-wp' ),
				);
				foreach ( $checks as $key => $label ) :
					?>
					<p>
						<input type="hidden" name="<?php echo esc_attr( $option_name ); ?>[<?php echo esc_attr( $key ); ?>]" value="0" />
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $option_name ); ?>[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( ! empty( $settings[ $key ] ) ); ?> />
							<?php echo esc_html( $label ); ?>
						</label>
					</p>
				<?php endforeach; ?>
			</fieldset>

			<fieldset class="ai-ready-wp__card">
				<legend><?php esc_html_e( 'Documents', 'ai-ready-wp' ); ?></legend>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Include', 'ai-ready-wp' ); ?></th>
							<th><?php esc_html_e( 'Type', 'ai-ready-wp' ); ?></th>
							<th><?php esc_html_e( 'Section label', 'ai-ready-wp' ); ?></th>
							<th><?php esc_html_e( 'Order', 'ai-ready-wp' ); ?></th>
							<th><?php esc_html_e( 'Optional', 'ai-ready-wp' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $post_types as $slug => $label ) : ?>
							<tr>
								<td>
									<label>
										<input type="checkbox" name="<?php echo esc_attr( $option_name ); ?>[post_types][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $settings['post_types'], true ) ); ?> />
										<span class="screen-reader-text"><?php echo esc_html( $label ); ?></span>
									</label>
								</td>
								<td><code><?php echo esc_html( $slug ); ?></code></td>
								<td>
									<input type="text" class="regular-text" name="<?php echo esc_attr( $option_name ); ?>[section_labels][<?php echo esc_attr( $slug ); ?>]" value="<?php echo esc_attr( isset( $settings['section_labels'][ $slug ] ) ? $settings['section_labels'][ $slug ] : '' ); ?>" placeholder="<?php echo esc_attr( $label ); ?>" maxlength="80" />
								</td>
								<td>
									<input type="number" min="0" max="9999" step="1" name="<?php echo esc_attr( $option_name ); ?>[section_ranks][<?php echo esc_attr( $slug ); ?>]" value="<?php echo esc_attr( isset( $settings['section_ranks'][ $slug ] ) ? (int) $settings['section_ranks'][ $slug ] : 10 ); ?>" />
								</td>
								<td>
									<label>
										<input type="checkbox" name="<?php echo esc_attr( $option_name ); ?>[optional_post_types][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $settings['optional_post_types'], true ) ); ?> />
										<span class="screen-reader-text">
											<?php
											echo esc_html(
												sprintf(
													/* translators: %s: post type label. */
													__( 'Mark %s as optional', 'ai-ready-wp' ),
													$label
												)
											);
											?>
										</span>
									</label>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( 'Lower numbers are listed first. Optional types are grouped under the Optional section, which agents may skip.', 'ai-ready-wp' ); ?></p>
				<p>
					<label for="ai-ready-exclude"><?php esc_html_e( 'Exclude IDs, slugs, or paths', 'ai-ready-wp' ); ?></label><br />
					<textarea id="ai-ready-exclude" name="<?php echo esc_attr( $option_name ); ?>[exclude]" rows="4" class="large-text code"><?php echo esc_textarea( $settings['exclude'] ); ?></textarea>
				</p>
				<p class="description"><?php esc_html_e( 'Separate items with commas or new lines. Example: 42, contact, parent/child.', 'ai-ready-wp' ); ?></p>
			</fieldset>

			<fieldset class="ai-ready-wp__card">
				<legend><?php esc_html_e( 'Introduction', 'ai-ready-wp' ); ?></legend>
				<p>
					<label for="ai-ready-summary"><?php esc_html_e( 'Summary', 'ai-ready-wp' ); ?></label><br />
					<input id="ai-ready-summary" type="text" class="large-text" maxlength="300" name="<?php echo esc_attr( $option_name ); ?>[summary]" value="<?php echo esc_attr( $settings['summary'] ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'description' ) ); ?>" />
				</p>
				<p class="description"><?php esc_html_e( 'Used as the blockquote under the site name. Leave blank to use the tagline.', 'ai-ready-wp' ); ?></p>
				<p>
					<label for="ai-ready-intro"><?php esc_html_e( 'Details', 'ai-ready-wp' ); ?></label><br />
					<textarea id="ai-ready-intro" name="<?php echo esc_attr( $option_name ); ?>[intro]" rows="5" class="large-text"><?php echo esc_textarea( $settings['intro'] ); ?></textarea>
				</p>
				<p class="description"><?php esc_html_e( 'Shown after the summary and before the sections. Headings are flattened so they cannot be mistaken for a file list.', 'ai-ready-wp' ); ?></p>
			</fieldset>

			<details class="ai-ready-wp__card">
				<summary><?php esc_html_e( 'Limits', 'ai-ready-wp' ); ?></summary>
				<p>
					<label for="ai-ready-max-items"><?php esc_html_e( 'Maximum documents in llms.txt', 'ai-ready-wp' ); ?></label><br />
					<input id="ai-ready-max-items" type="number" min="1" max="5000" name="<?php echo esc_attr( $option_name ); ?>[max_items]" value="<?php echo esc_attr( (int) $settings['max_items'] ); ?>" />
				</p>
				<p>
					<label for="ai-ready-max-full"><?php esc_html_e( 'Maximum documents in llms-full.txt', 'ai-ready-wp' ); ?></label><br />
					<input id="ai-ready-max-full" type="number" min="0" max="2000" name="<?php echo esc_attr( $option_name ); ?>[max_full_items]" value="<?php echo esc_attr( (int) $settings['max_full_items'] ); ?>" />
				</p>
				<p>
					<label for="ai-ready-ttl"><?php esc_html_e( 'Cache lifetime (minutes)', 'ai-ready-wp' ); ?></label><br />
					<input id="ai-ready-ttl" type="number" min="1" max="43200" name="<?php echo esc_attr( $option_name ); ?>[cache_ttl_minutes]" value="<?php echo esc_attr( (int) $minutes ); ?>" />
				</p>
			</details>

			<?php submit_button( __( 'Save settings', 'ai-ready-wp' ) ); ?>
		</form>

		<aside class="ai-ready-wp__preview" aria-labelledby="ai-ready-preview-title">
			<div class="ai-ready-wp__preview-bar">
				<h2 id="ai-ready-preview-title"><?php esc_html_e( 'llms.txt preview', 'ai-ready-wp' ); ?></h2>
				<button type="button" class="button" id="ai-ready-wp-copy"><?php esc_html_e( 'Copy', 'ai-ready-wp' ); ?></button>
			</div>
			<p id="ai-ready-wp-preview-status" class="ai-ready-wp__preview-status" aria-live="polite"><?php esc_html_e( 'Preview of the saved settings.', 'ai-ready-wp' ); ?></p>
			<pre id="ai-ready-wp-preview" class="ai-ready-wp__preview-body"><?php echo esc_html( $preview ); ?></pre>
		</aside>
	</div>
</div>
