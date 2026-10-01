<?php
/**
 * admin_post CSV stream exporter.
 *
 * @package VDI_CPT_ACF_Export
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles admin-post export: capability, nonce, whitelist, paged CSV stream.
 */
class VDI_CPT_ACF_Export_Exporter {

	const ACTION     = 'vdi_cpt_acf_export';
	const PAGE_SIZE  = 200;

	/**
	 * Register WordPress hooks.
	 */
	public function hooks() {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_export' ) );
	}

	/**
	 * Process export request: fail closed with notices, or stream CSV.
	 */
	public function handle_export() {
		// Capability — fail closed.
		if ( ! current_user_can( 'export' ) ) {
			$this->fail_redirect(
				'error',
				__( 'You do not have permission to export content.', 'vdi-cpt-acf-export' )
			);
		}

		// Nonce — fail closed (check_admin_referer dies by default; we catch soft fail via referer check pattern).
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), self::ACTION ) ) {
			$this->fail_redirect(
				'error',
				__( 'Security check failed. Please try again from the export page.', 'vdi-cpt-acf-export' )
			);
		}

		$post_type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';
		$allowed   = get_post_types( array( 'public' => true ) );

		if ( '' === $post_type || ! in_array( $post_type, $allowed, true ) ) {
			$this->fail_redirect(
				'error',
				__( 'That post type is not available for export. Choose a public post type and try again.', 'vdi-cpt-acf-export' )
			);
		}

		$discovery = new VDI_CPT_ACF_Export_Field_Discovery();
		$acf_fields = $discovery->get_exportable_fields( $post_type );

		// Peek first page to detect zero matching posts — no empty CSV download.
		$first = new WP_Query(
			array(
				'post_type'              => $post_type,
				'post_status'            => 'any',
				'posts_per_page'         => 1,
				'paged'                  => 1,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		if ( ! $first->have_posts() || (int) $first->found_posts < 1 ) {
			wp_reset_postdata();
			$this->fail_redirect(
				'info',
				__( 'No posts matched.', 'vdi-cpt-acf-export' )
			);
		}
		wp_reset_postdata();

		$this->stream_csv( $post_type, $acf_fields );
	}

	/**
	 * Redirect to Tools page with a dismissible notice and exit.
	 *
	 * @param string $type    success|info|warning|error.
	 * @param string $message Plain-language message.
	 */
	private function fail_redirect( $type, $message ) {
		VDI_CPT_ACF_Export_Admin_Page::set_notice( $type, $message );
		wp_safe_redirect( VDI_CPT_ACF_Export_Admin_Page::tools_url() );
		exit;
	}

	/**
	 * Stream CSV to browser (UTF-8 BOM). Does not write under web root.
	 *
	 * @param string $post_type  Whitelisted post type.
	 * @param array  $acf_fields Exportable field defs from discovery.
	 */
	private function stream_csv( $post_type, $acf_fields ) {
		$filename = sprintf(
			'%s-export-%s.csv',
			$post_type,
			current_time( 'Y-m-d' )
		);

		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) {
			$this->fail_redirect(
				'error',
				__( 'Could not open the download stream. Please try again.', 'vdi-cpt-acf-export' )
			);
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		// UTF-8 BOM for Excel.
		fwrite( $out, "\xEF\xBB\xBF" );

		// Header row: core labels, then ACF field labels (fallback name).
		$header = array( 'ID', 'Title', 'Status', 'Date' );
		foreach ( $acf_fields as $field ) {
			$header[] = ( isset( $field['label'] ) && '' !== $field['label'] )
				? $field['label']
				: $field['name'];
		}
		fputcsv( $out, $header );

		$paged = 1;
		do {
			$query = new WP_Query(
				array(
					'post_type'              => $post_type,
					'post_status'            => 'any',
					'posts_per_page'         => self::PAGE_SIZE,
					'paged'                  => $paged,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'no_found_rows'          => false,
					'update_post_meta_cache' => true,
					'update_post_term_cache' => false,
				)
			);

			if ( ! $query->have_posts() ) {
				wp_reset_postdata();
				break;
			}

			while ( $query->have_posts() ) {
				$query->the_post();
				$post    = get_post();
				$post_id = (int) $post->ID;

				$row = array(
					(string) $post_id,
					(string) $post->post_title,
					(string) $post->post_status,
					(string) $post->post_date,
				);

				foreach ( $acf_fields as $field ) {
					$value = function_exists( 'get_field' )
						? get_field( $field['name'], $post_id )
						: null;
					$row[] = $this->flatten_value( $value, isset( $field['type'] ) ? $field['type'] : '' );
				}

				fputcsv( $out, $row );
			}

			wp_reset_postdata();
			$max_pages = (int) $query->max_num_pages;
			$paged++;
		} while ( $paged <= $max_pages );

		fclose( $out );
		exit;
	}

	/**
	 * Flatten an ACF value for a CSV cell (scalars / checkbox only in v0).
	 *
	 * @param mixed  $value Field value from get_field().
	 * @param string $type  ACF field type.
	 * @return string
	 */
	private function flatten_value( $value, $type ) {
		if ( null === $value || false === $value ) {
			return '';
		}

		if ( 'wysiwyg' === $type && is_string( $value ) ) {
			return wp_strip_all_tags( $value );
		}

		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		// Checkbox (and similar): array of scalars → comma-join.
		if ( is_array( $value ) ) {
			$parts = array();
			foreach ( $value as $item ) {
				if ( is_scalar( $item ) ) {
					$parts[] = (string) $item;
				} else {
					// Complex nested junk — skip entire cell for v0.
					return '';
				}
			}
			return implode( ', ', $parts );
		}

		// Objects / other complex — empty in v0 (no JSON yet).
		return '';
	}
}
