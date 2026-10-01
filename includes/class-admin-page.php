<?php
/**
 * Tools submenu: CPT + ACF Export form.
 *
 * @package VDI_CPT_ACF_Export
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the Tools → CPT + ACF Export page and transient admin notices.
 */
class VDI_CPT_ACF_Export_Admin_Page {

	const NOTICE_TRANSIENT = 'vdi_cpt_acf_export_notice';
	const PAGE_SLUG        = 'vdi-cpt-acf-export';

	/**
	 * Register WordPress hooks.
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_notices', array( $this, 'render_transient_notice' ) );
	}

	/**
	 * Add Tools submenu “CPT + ACF Export”.
	 */
	public function register_menu() {
		add_management_page(
			__( 'CPT + ACF Export', 'vdi-cpt-acf-export' ),
			__( 'CPT + ACF Export', 'vdi-cpt-acf-export' ),
			'export',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Tools page URL for redirects.
	 *
	 * @return string
	 */
	public static function tools_url() {
		return admin_url( 'tools.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * Store a one-shot admin notice (type: success|info|warning|error).
	 *
	 * @param string $type    Notice class suffix.
	 * @param string $message Plain-language message.
	 */
	public static function set_notice( $type, $message ) {
		set_transient(
			self::NOTICE_TRANSIENT . '_' . get_current_user_id(),
			array(
				'type'    => sanitize_key( $type ),
				'message' => $message,
			),
			60
		);
	}

	/**
	 * Print and clear redirect notice if present.
	 */
	public function render_transient_notice() {
		if ( ! current_user_can( 'export' ) ) {
			return;
		}
		$key  = self::NOTICE_TRANSIENT . '_' . get_current_user_id();
		$data = get_transient( $key );
		if ( ! is_array( $data ) || empty( $data['message'] ) ) {
			return;
		}
		delete_transient( $key );

		$type    = isset( $data['type'] ) ? $data['type'] : 'info';
		$allowed = array( 'success', 'info', 'warning', 'error' );
		if ( ! in_array( $type, $allowed, true ) ) {
			$type = 'info';
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $data['message'] )
		);
	}

	/**
	 * Whether any public CPT has exportable ACF fields (for Tools warning).
	 *
	 * @param array $post_types Post type objects from get_post_types( ..., 'objects' ).
	 * @return bool
	 */
	private function any_cpt_has_acf_fields( $post_types ) {
		$discovery = new VDI_CPT_ACF_Export_Field_Discovery();
		if ( ! $discovery->is_acf_available() ) {
			return false;
		}
		foreach ( $post_types as $pt ) {
			if ( ! empty( $discovery->get_exportable_fields( $pt->name ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Render the export form.
	 */
	public function render_page() {
		if ( ! current_user_can( 'export' ) ) {
			wp_die( esc_html__( 'You do not have permission to export.', 'vdi-cpt-acf-export' ) );
		}

		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		$has_cpts   = ! empty( $post_types );
		$acf_active = function_exists( 'acf_get_field_groups' );
		$has_acf    = $acf_active && $this->any_cpt_has_acf_fields( $post_types );

		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'CPT + ACF Export', 'vdi-cpt-acf-export' ); ?></h1>

			<?php if ( ! $acf_active ) : ?>
				<div class="notice notice-warning"><p>
					<?php
					echo esc_html__(
						'Advanced Custom Fields (ACF) is not active. You can still export core columns (ID, Title, Status, Date) and attached taxonomies. Custom field columns require ACF.',
						'vdi-cpt-acf-export'
					);
					?>
				</p></div>
			<?php elseif ( ! $has_acf ) : ?>
				<div class="notice notice-warning"><p>
					<?php
					echo esc_html__(
						'No ACF field groups with exportable (scalar) fields were found for public post types. Export will still include core columns (ID, Title, Status, Date) and attached taxonomies. If groups target a specific CPT, pick that type and export — columns are discovered per post type.',
						'vdi-cpt-acf-export'
					);
					?>
				</p></div>
			<?php endif; ?>

			<?php if ( ! $has_cpts ) : ?>
				<div class="notice notice-info"><p>
					<?php echo esc_html__( 'No public post types are available to export.', 'vdi-cpt-acf-export' ); ?>
				</p></div>
			<?php endif; ?>

			<p>
				<?php
				echo esc_html__(
					'Export posts for a public post type, including attached built-in and custom taxonomies. CSV retains ID, Title, Status, Date and scalar ACF columns, then adds taxonomy assignments as comma-separated term names. Choose JSON for taxonomy definitions, term metadata, ancestor terms, and exact post assignments. Complex ACF fields are skipped. Protected term metadata is excluded; other term metadata may contain sensitive plugin-specific data.',
					'vdi-cpt-acf-export'
				);
				?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="vdi_cpt_acf_export" />
				<?php wp_nonce_field( 'vdi_cpt_acf_export' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="vdi-cpt-acf-export-post-type"><?php echo esc_html__( 'Post type', 'vdi-cpt-acf-export' ); ?></label>
						</th>
						<td>
							<select name="post_type" id="vdi-cpt-acf-export-post-type" <?php disabled( ! $has_cpts ); ?>>
								<?php foreach ( $post_types as $pt ) : ?>
									<option value="<?php echo esc_attr( $pt->name ); ?>">
										<?php echo esc_html( $pt->labels->singular_name ? $pt->labels->singular_name : $pt->label ); ?>
										(<?php echo esc_html( $pt->name ); ?>)
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vdi-export-format"><?php echo esc_html__( 'Export format', 'vdi-cpt-acf-export' ); ?></label></th>
						<td><select name="export_format" id="vdi-export-format">
							<option value="csv"><?php echo esc_html__( 'CSV — posts and assigned terms', 'vdi-cpt-acf-export' ); ?></option>
							<option value="json"><?php echo esc_html__( 'JSON — posts and complete taxonomy data', 'vdi-cpt-acf-export' ); ?></option>
						</select></td>
					</tr>
					<tr>
						<th scope="row"><label for="vdi-term-scope"><?php echo esc_html__( 'JSON term scope', 'vdi-cpt-acf-export' ); ?></label></th>
						<td><select name="term_scope" id="vdi-term-scope">
							<option value="assigned"><?php echo esc_html__( 'Assigned terms and ancestors', 'vdi-cpt-acf-export' ); ?></option>
							<option value="all"><?php echo esc_html__( 'All terms in attached taxonomies', 'vdi-cpt-acf-export' ); ?></option>
						</select>
						<p class="description"><?php echo esc_html__( 'Applies to JSON only. CSV always includes direct assignments only.', 'vdi-cpt-acf-export' ); ?></p></td>
					</tr>
				</table>

				<?php
				submit_button(
					__( 'Download export', 'vdi-cpt-acf-export' ),
					'primary',
					'submit',
					true,
					$has_cpts ? array() : array( 'disabled' => 'disabled' )
				);
				?>
			</form>
		</div>
		<?php
	}
}
