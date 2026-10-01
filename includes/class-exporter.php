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

		$format = isset( $_POST['export_format'] ) && is_string( $_POST['export_format'] )
			? sanitize_key( wp_unslash( $_POST['export_format'] ) ) : 'csv';
		$term_scope = isset( $_POST['term_scope'] ) && is_string( $_POST['term_scope'] )
			? sanitize_key( wp_unslash( $_POST['term_scope'] ) ) : 'assigned';
		if ( ! in_array( $format, array( 'csv', 'json' ), true ) || ! in_array( $term_scope, array( 'assigned', 'all' ), true ) ) {
			$this->fail_redirect( 'error', __( 'Choose a valid export format and term scope.', 'vdi-cpt-acf-export' ) );
		}

		$this->stream_export( $post_type, $acf_fields, $format, $term_scope );
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
	 * Stage the export before sending headers so taxonomy failures cannot produce
	 * a successful-looking, incomplete download. Temporary files are outside the
	 * web root and are automatically removed when closed.
	 */
	private function stream_export( $post_type, $acf_fields, $format, $term_scope ) {
		$out = tmpfile();
		if ( false === $out ) {
			$this->fail_redirect( 'error', __( 'Could not open the temporary export stream.', 'vdi-cpt-acf-export' ) );
		}
		try {
			$this->write_export( $out, $post_type, $acf_fields, $format, $term_scope );
			if ( ! rewind( $out ) ) {
				throw new RuntimeException( __( 'Could not rewind the export stream.', 'vdi-cpt-acf-export' ) );
			}
		} catch ( RuntimeException $error ) {
			fclose( $out );
			wp_reset_postdata();
			$this->fail_redirect( 'error', $error->getMessage() );
		}

		$filename = sprintf( '%s-export-%s.%s', $post_type, current_time( 'Y-m-d' ), $format );
		nocache_headers();
		header( 'Content-Type: ' . ( 'json' === $format ? 'application/json' : 'text/csv' ) . '; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		fpassthru( $out );
		fclose( $out );
		exit;
	}

	/**
	 * Write paged posts and taxonomy data with only term keys retained globally.
	 */
	private function write_export( $out, $post_type, $acf_fields, $format, $term_scope ) {
		$collector  = new VDI_CPT_ACF_Export_Taxonomy_Export();
		$taxonomies = $collector->get_taxonomies( $post_type );
		$terms_out  = 'json' === $format ? tmpfile() : null;
		if ( false === $terms_out ) {
			throw new RuntimeException( __( 'Could not open the temporary term stream.', 'vdi-cpt-acf-export' ) );
		}
		$seen        = array();
		$first_post  = true;
		try {
			if ( 'csv' === $format ) {
				$this->write_bytes( $out, "\xEF\xBB\xBF" );
				$header = array( 'ID', 'Title', 'Status', 'Date' );
				foreach ( $acf_fields as $field ) {
					$header[] = ! empty( $field['label'] ) ? $field['label'] : $field['name'];
				}
				foreach ( $taxonomies as $name => $taxonomy ) {
					$header[] = 'Taxonomy: ' . $name;
				}
				$this->write_csv_row( $out, $header );
			} else {
				$prefix = array(
					'schema_version' => 1,
					'post_type'      => $post_type,
					'term_scope'     => $term_scope,
					'acf_fields'     => $acf_fields,
					'taxonomies'     => $collector->get_definitions( $post_type, $taxonomies ),
				);
				$this->write_bytes( $out, substr( $this->encode_json( $prefix ), 0, -1 ) . ',"posts":[' );
			}

			$paged = 1;
			do {
				$query = new WP_Query( array(
					'post_type' => $post_type, 'post_status' => 'any',
					'posts_per_page' => self::PAGE_SIZE, 'paged' => $paged,
					'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => false,
					'update_post_meta_cache' => true, 'update_post_term_cache' => false,
				) );
				if ( empty( $query->posts ) ) {
					break;
				}
				$batch = $collector->get_batch( wp_list_pluck( $query->posts, 'ID' ), $taxonomies );
				$this->check_taxonomy_result( $batch );
				if ( 'json' === $format ) {
					$this->write_terms( $terms_out, $batch['terms'], $seen );
				}
				foreach ( $query->posts as $post ) {
					$post_id = (int) $post->ID;
					$row = array( (string) $post_id, (string) $post->post_title, (string) $post->post_status, (string) $post->post_date );
					$acf = array();
					foreach ( $acf_fields as $field ) {
						$value = function_exists( 'get_field' ) ? get_field( $field['name'], $post_id ) : null;
						$value = $this->flatten_value( $value, isset( $field['type'] ) ? $field['type'] : '' );
						$row[] = $value;
						$acf[ $field['name'] ] = $value;
					}
					$relationships = isset( $batch['relationships'][ $post_id ] ) ? $batch['relationships'][ $post_id ] : array();
					if ( 'csv' === $format ) {
						foreach ( $taxonomies as $name => $taxonomy ) {
							$assigned = array();
							foreach ( $relationships[ $name ] as $reference ) {
								$term = $batch['terms'][ $name . ':' . $reference['source_term_id'] ];
								$assigned[] = $term['name'];
							}
							$row[] = implode( ', ', $assigned );
						}
						$this->write_csv_row( $out, $row );
					} else {
						$record = array(
							'ID' => $post_id, 'Title' => $post->post_title, 'Status' => $post->post_status, 'Date' => $post->post_date,
							'acf' => (object) $acf, 'taxonomies' => (object) $relationships,
						);
						$this->write_bytes( $out, ( $first_post ? '' : ',' ) . $this->encode_json( $record ) );
						$first_post = false;
					}
				}
				$paged++;
			} while ( $paged <= (int) $query->max_num_pages );

			if ( 'json' === $format ) {
				if ( 'all' === $term_scope ) {
					$offset = 0;
					do {
						$terms = $collector->get_all_terms( $taxonomies, $offset, self::PAGE_SIZE );
						$this->check_taxonomy_result( $terms );
						$this->write_terms( $terms_out, $terms, $seen );
						$offset += self::PAGE_SIZE;
					} while ( ! empty( $terms ) );
				}
				$this->write_bytes( $out, '],"terms":[' );
				if ( ! rewind( $terms_out ) || false === stream_copy_to_stream( $terms_out, $out ) ) {
					throw new RuntimeException( __( 'Could not copy the term export stream.', 'vdi-cpt-acf-export' ) );
				}
				$this->write_bytes( $out, ']}' );
			}
		} finally {
			if ( is_resource( $terms_out ) ) {
				fclose( $terms_out );
			}
		}
	}

	private function check_taxonomy_result( $result ) {
		if ( is_wp_error( $result ) ) {
			throw new RuntimeException( $result->get_error_message() );
		}
	}

	private function write_terms( $out, $terms, &$seen ) {
		foreach ( $terms as $key => $term ) {
			if ( ! isset( $seen[ $key ] ) ) {
				$this->write_bytes( $out, ( empty( $seen ) ? '' : ',' ) . $this->encode_json( $term ) );
				$seen[ $key ] = true;
			}
		}
	}

	private function encode_json( $value ) {
		$json = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			throw new RuntimeException( __( 'Could not encode export data as JSON.', 'vdi-cpt-acf-export' ) );
		}
		return $json;
	}

	private function write_bytes( $out, $bytes ) {
		if ( strlen( $bytes ) !== fwrite( $out, $bytes ) ) {
			throw new RuntimeException( __( 'Could not write export data. Check temporary disk space.', 'vdi-cpt-acf-export' ) );
		}
	}

	private function write_csv_row( $out, $row ) {
		if ( false === fputcsv( $out, $row, ',', '"', '' ) ) {
			throw new RuntimeException( __( 'Could not write the CSV export.', 'vdi-cpt-acf-export' ) );
		}
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
