<?php
/**
 * Settings screen under Settings > AI Ready.
 *
 * @package AiReady
 */

namespace AiReady;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Options page. Every action checks manage_options and a nonce.
 */
final class AdminPage {

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_ai_ready_save', array( $this, 'save' ) );
		add_action( 'admin_post_ai_ready_generate', array( $this, 'generate' ) );
	}

	public function menu(): void {
		add_options_page(
			__( 'AI Ready', 'ai-ready-wp' ),
			__( 'AI Ready', 'ai-ready-wp' ),
			'manage_options',
			'ai-ready',
			array( $this, 'render' )
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = Settings::current();
		$store    = Store::open();
		$types    = $this->public_types();
		$count    = count( $store->document_ids() );
		$when     = $store->generated_at();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'AI Ready', 'ai-ready-wp' ); ?></h1>
			<p><?php echo esc_html__( 'Single-site only. Files are written on this server and nothing is sent to an external service.', 'ai-ready-wp' ); ?></p>
			<?php $this->notices(); ?>
			<p>
				<?php
				if ( $store->has_index() ) {
					echo esc_html(
						sprintf(
							/* translators: 1: document count, 2: UTC timestamp */
							__( 'Last generated %2$s. %1$d public document(s).', 'ai-ready-wp' ),
							$count,
							gmdate( 'Y-m-d H:i:s', $when ) . ' UTC'
						)
					);
					echo ' <a href="' . esc_url( home_url( '/llms.txt' ) ) . '">' . esc_html__( 'View llms.txt', 'ai-ready-wp' ) . '</a>';
					if ( $store->has_full() ) {
						echo ' <a href="' . esc_url( home_url( '/llms-full.txt' ) ) . '">' . esc_html__( 'View llms-full.txt', 'ai-ready-wp' ) . '</a>';
					}
				} else {
					echo esc_html__( 'No files yet.', 'ai-ready-wp' );
				}
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ai_ready_generate" />
				<?php wp_nonce_field( 'ai_ready_generate' ); ?>
				<?php submit_button( __( 'Generate files', 'ai-ready-wp' ), 'primary', 'submit', false ); ?>
			</form>
			<hr />
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ai_ready_save" />
				<?php wp_nonce_field( 'ai_ready_save_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ai_ready_summary"><?php echo esc_html__( 'Summary', 'ai-ready-wp' ); ?></label></th>
						<td>
							<textarea name="summary" id="ai_ready_summary" class="large-text" rows="3"><?php echo esc_textarea( $settings->summary() ); ?></textarea>
							<p class="description"><?php echo esc_html__( 'One sentence under the site title in llms.txt. Leave this blank to reuse the tagline.', 'ai-ready-wp' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ai_ready_details"><?php echo esc_html__( 'Details', 'ai-ready-wp' ); ?></label></th>
						<td>
							<textarea name="details" id="ai_ready_details" class="large-text" rows="4"><?php echo esc_textarea( $settings->details() ); ?></textarea>
							<p class="description"><?php echo esc_html__( 'Optional short note between the summary and the link lists.', 'ai-ready-wp' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Post types', 'ai-ready-wp' ); ?></th>
						<td>
							<?php foreach ( $types as $name => $label ) : ?>
								<label style="display:block;margin:0 0 4px;">
									<input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $name ); ?>" <?php checked( in_array( $name, $settings->post_types(), true ) ); ?> />
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Optional sections', 'ai-ready-wp' ); ?></th>
						<td>
							<?php foreach ( $types as $name => $label ) : ?>
								<label style="display:block;margin:0 0 4px;">
									<input type="checkbox" name="optional_types[]" value="<?php echo esc_attr( $name ); ?>" <?php checked( in_array( $name, $settings->optional_types(), true ) ); ?> />
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
							<p class="description"><?php echo esc_html__( 'Checked types are grouped under an Optional heading.', 'ai-ready-wp' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Full file', 'ai-ready-wp' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="write_full" value="1" <?php checked( $settings->write_full() ); ?> />
								<?php echo esc_html__( 'Also write llms-full.txt', 'ai-ready-wp' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ai_ready_note_length"><?php echo esc_html__( 'Note length', 'ai-ready-wp' ); ?></label></th>
						<td>
							<input type="number" min="40" max="500" name="note_length" id="ai_ready_note_length" value="<?php echo esc_attr( (string) $settings->note_length() ); ?>" />
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save settings', 'ai-ready-wp' ) ); ?>
			</form>
		</div>
		<?php
	}

	public function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage AI Ready settings.', 'ai-ready-wp' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'ai_ready_save_settings' );
		$post_types = array();
		if ( isset( $_POST['post_types'] ) && is_array( $_POST['post_types'] ) ) {
			foreach ( wp_unslash( $_POST['post_types'] ) as $type ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is passed through sanitize_key.
				if ( is_string( $type ) ) {
					$post_types[] = sanitize_key( $type );
				}
			}
		}
		$optional = array();
		if ( isset( $_POST['optional_types'] ) && is_array( $_POST['optional_types'] ) ) {
			foreach ( wp_unslash( $_POST['optional_types'] ) as $type ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is passed through sanitize_key.
				if ( is_string( $type ) ) {
					$optional[] = sanitize_key( $type );
				}
			}
		}
		$clean = Settings::from_array(
			array(
				'summary'        => isset( $_POST['summary'] ) ? sanitize_text_field( wp_unslash( $_POST['summary'] ) ) : '',
				'details'        => isset( $_POST['details'] ) ? sanitize_textarea_field( wp_unslash( $_POST['details'] ) ) : '',
				'post_types'     => $post_types,
				'optional_types' => $optional,
				'write_full'     => isset( $_POST['write_full'] ) ? '1' : '',
				'note_length'    => isset( $_POST['note_length'] ) ? absint( wp_unslash( $_POST['note_length'] ) ) : 200,
			),
			array_keys( $this->public_types() )
		);
		update_option( self::option_name(), $clean->to_array(), true );
		$result = Generator::trusted(
			static function () {
				return ( new Generator() )->run();
			}
		);
		if ( is_wp_error( $result ) ) {
			$this->remember_error( $result );
			$this->finish( add_query_arg( 'ai_ready_error', '1', $this->url() ) );
			return;
		}
		delete_transient( Store::ERROR_TRANSIENT );
		$this->finish( add_query_arg( 'updated', '1', $this->url() ) );
	}

	public function generate(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage AI Ready settings.', 'ai-ready-wp' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'ai_ready_generate' );
		$count = Generator::trusted(
			static function () {
				return ( new Generator() )->run( true );
			}
		);
		if ( is_wp_error( $count ) ) {
			$this->remember_error( $count );
			$this->finish( add_query_arg( 'ai_ready_error', '1', $this->url() ) );
			return;
		}
		delete_transient( Store::ERROR_TRANSIENT );
		$this->finish(
			add_query_arg(
				array(
					'generated' => '1',
					'count'     => (int) $count,
				),
				$this->url()
			)
		);
	}

	/**
	 * @param \WP_Error $error Storage or generation error.
	 */
	private function remember_error( \WP_Error $error ): void {
		$message = $error->get_error_message();
		if ( ! is_string( $message ) || '' === $message ) {
			$message = __( 'AI Ready could not save the generated output.', 'ai-ready-wp' );
		}
		set_transient( Store::ERROR_TRANSIENT, $message, MINUTE_IN_SECONDS );
	}

	/**
	 * Redirect and stop. Tests intercept this so the suite can keep running.
	 */
	private function finish( string $url ): void {
		if ( defined( 'AI_READY_TESTING' ) && AI_READY_TESTING ) {
			// The suite already sent output, so a real redirect header would be a warning.
			throw new \RuntimeException( 'redirect' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test signal, not rendered output.
		}
		wp_safe_redirect( $url );
		exit;
	}

	private function notices(): void {
		// Redirect flags from our own admin-post handlers, not a form submission.
		if ( isset( $_GET['updated'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['updated'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'ai-ready-wp' ) . '</p></div>';
		}
		if ( isset( $_GET['ai_ready_error'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['ai_ready_error'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$stored  = get_transient( Store::ERROR_TRANSIENT );
			$message = is_string( $stored ) && '' !== $stored
				? $stored
				: __( 'AI Ready could not save the generated output.', 'ai-ready-wp' );
			echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
		}
		if ( isset( $_GET['generated'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['generated'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$count = isset( $_GET['count'] ) ? absint( wp_unslash( $_GET['count'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success"><p>' . esc_html(
				sprintf(
					/* translators: %d: number of documents written */
					__( 'Generated files for %d document(s).', 'ai-ready-wp' ),
					$count
				)
			) . '</p></div>';
		}
	}

	/**
	 * @return array<string, string>
	 */
	private function public_types(): array {
		$objects = get_post_types( array( 'public' => true ), 'objects' );
		$types   = array();
		if ( ! is_array( $objects ) ) {
			return $types;
		}
		foreach ( $objects as $name => $object ) {
			if ( 'attachment' === $name || ! $object instanceof \WP_Post_Type ) {
				continue;
			}
			$label          = ( isset( $object->labels->name ) && is_string( $object->labels->name ) ) ? $object->labels->name : (string) $name;
			$types[ $name ] = $label;
		}
		return $types;
	}

	private function url(): string {
		return admin_url( 'options-general.php?page=ai-ready' );
	}

	private static function option_name(): string {
		return Settings::OPTION;
	}
}
