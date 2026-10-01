<?php
/**
 * Portable taxonomy definitions, relationships, and term records.
 *
 * @package VDI_CPT_ACF_Export
 */

defined( 'ABSPATH' ) || exit;

/**
 * Exports taxonomy data without retaining term data between calls.
 *
 * Taxonomy arguments accept lists of names or name => WP_Taxonomy maps.
 * Term records are keyed by "taxonomy:source_term_id"; parent and relationship
 * references contain taxonomy and source_term_id, never destination IDs.
 */
class VDI_CPT_ACF_Export_Taxonomy_Export {

	/**
	 * Discover attached taxonomies, including private and built-in taxonomies.
	 *
	 * @param string $post_type Selected post type.
	 * @return array<string, WP_Taxonomy>
	 */
	public function get_taxonomies( $post_type ) {
		$taxonomies = get_object_taxonomies( $post_type, 'objects' );
		$internal   = array( 'nav_menu', 'link_category', 'wp_theme', 'wp_template_part', 'wp_pattern_category' );
		$result     = array();

		foreach ( $taxonomies as $taxonomy ) {
			if ( $taxonomy instanceof WP_Taxonomy && ! in_array( $taxonomy->name, $internal, true ) ) {
				$result[ $taxonomy->name ] = $taxonomy;
			}
		}
		ksort( $result, SORT_STRING );
		return $result;
	}

	/**
	 * Return allowlisted definitions attached to the selected post type.
	 *
	 * Callbacks, capabilities, and unrelated object types are not exported.
	 *
	 * @param string $post_type  Selected post type.
	 * @param array  $taxonomies Taxonomy names or objects.
	 * @return array List of JSON-safe descriptors.
	 */
	public function get_definitions( $post_type, $taxonomies ) {
		$available  = $this->get_taxonomies( $post_type );
		$selected   = $this->taxonomy_names( $taxonomies );
		$result     = array();
		$label_keys = array(
			'name', 'singular_name', 'search_items', 'popular_items', 'all_items',
			'parent_item', 'parent_item_colon', 'name_field_description',
			'slug_field_description', 'parent_field_description', 'desc_field_description',
			'edit_item', 'view_item', 'update_item', 'add_new_item', 'new_item_name',
			'separate_items_with_commas', 'add_or_remove_items', 'choose_from_most_used',
			'not_found', 'no_terms', 'filter_by_item', 'items_list_navigation',
			'items_list', 'most_used', 'back_to_items', 'item_link',
			'item_link_description', 'menu_name',
		);

		foreach ( $selected as $name ) {
			if ( ! isset( $available[ $name ] ) ) {
				continue;
			}
			$taxonomy = $available[ $name ];
			$labels   = array();
			foreach ( $label_keys as $key ) {
				if ( isset( $taxonomy->labels->$key ) && is_scalar( $taxonomy->labels->$key ) && ! is_float( $taxonomy->labels->$key ) ) {
					$labels[ $key ] = $taxonomy->labels->$key;
				}
			}

			$rewrite = false;
			if ( is_array( $taxonomy->rewrite ) ) {
				$rewrite = array();
				if ( isset( $taxonomy->rewrite['slug'] ) && is_string( $taxonomy->rewrite['slug'] ) ) {
					$rewrite['slug'] = $taxonomy->rewrite['slug'];
				}
				foreach ( array( 'with_front', 'hierarchical' ) as $flag ) {
					if ( isset( $taxonomy->rewrite[ $flag ] ) && is_scalar( $taxonomy->rewrite[ $flag ] ) ) {
						$rewrite[ $flag ] = (bool) $taxonomy->rewrite[ $flag ];
					}
				}
				if ( isset( $taxonomy->rewrite['ep_mask'] ) && is_int( $taxonomy->rewrite['ep_mask'] ) && $taxonomy->rewrite['ep_mask'] >= 0 ) {
					$rewrite['ep_mask'] = $taxonomy->rewrite['ep_mask'];
				}
			}

			$result[] = array(
				'name'           => $name,
				'label'          => is_string( $taxonomy->label ) ? $taxonomy->label : $name,
				'labels'         => $labels,
				'object_type'    => array_values( array_intersect( (array) $taxonomy->object_type, array( $post_type ) ) ),
				'builtin'        => (bool) $taxonomy->_builtin,
				'hierarchical'   => (bool) $taxonomy->hierarchical,
				'public'         => (bool) $taxonomy->public,
				'show_ui'        => (bool) $taxonomy->show_ui,
				'show_in_rest'   => (bool) $taxonomy->show_in_rest,
				'rest_base'      => isset( $taxonomy->rest_base ) && is_string( $taxonomy->rest_base ) ? $taxonomy->rest_base : false,
				'rest_namespace' => isset( $taxonomy->rest_namespace ) && is_string( $taxonomy->rest_namespace ) ? $taxonomy->rest_namespace : false,
				'query_var'      => is_string( $taxonomy->query_var ) ? $taxonomy->query_var : ( true === $taxonomy->query_var ),
				'rewrite'        => $rewrite,
			);
		}
		return $result;
	}

	/**
	 * Fetch direct relationships and complete term/ancestor records in a batch.
	 *
	 * Empty relationship lists are included for every requested post/taxonomy.
	 * Ancestors are records only, not additional direct relationships.
	 *
	 * @param int[] $post_ids   Source post IDs.
	 * @param array $taxonomies Taxonomy names or objects.
	 * @return array|WP_Error Relationships and keyed terms, or an actionable error.
	 */
	public function get_batch( $post_ids, $taxonomies ) {
		$names  = $this->taxonomy_names( $taxonomies );
		$result = array( 'relationships' => array(), 'terms' => array() );
		if ( empty( $names ) ) {
			return $result;
		}
		$post_ids = array_values( array_unique( array_filter( array_map( 'absint', $post_ids ) ) ) );
		foreach ( $post_ids as $post_id ) {
			$result['relationships'][ $post_id ] = array_fill_keys( $names, array() );
		}
		if ( empty( $post_ids ) ) {
			return $result;
		}

		$terms = wp_get_object_terms(
			$post_ids,
			$names,
			array( 'fields' => 'all_with_object_id', 'orderby' => 'term_id', 'order' => 'ASC' )
		);
		if ( is_wp_error( $terms ) ) {
			return $this->error( implode( ', ', $names ), 'Could not read post term relationships', $terms->get_error_message() );
		}

		$seen = array();
		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term || ! in_array( $term->taxonomy, $names, true ) || ! isset( $term->object_id, $result['relationships'][ (int) $term->object_id ] ) ) {
				return $this->error( implode( ', ', $names ), 'The relationship query returned an unexpected term', 'Check taxonomy query filters.' );
			}
			$error = $this->collect_term( $term, $result['terms'] );
			if ( is_wp_error( $error ) ) {
				return $error;
			}
			$post_id = (int) $term->object_id;
			$key     = $post_id . ':' . $this->reference_key( $term );
			if ( ! isset( $seen[ $key ] ) ) {
				$result['relationships'][ $post_id ][ $term->taxonomy ][] = $this->reference( $term );
				$seen[ $key ] = true;
			}
		}
		return $result;
	}

	/**
	 * Fetch a page of all terms, plus ancestors outside the requested page.
	 *
	 * Advance the offset by the requested limit, not by the returned record count:
	 * ancestors can enlarge the result. Term IDs are unique across taxonomies.
	 *
	 * @param array $taxonomies Taxonomy names or objects.
	 * @param int   $offset     Query offset.
	 * @param int   $limit      Positive page size.
	 * @return array|WP_Error Keyed term records or an actionable error.
	 */
	public function get_all_terms( $taxonomies, $offset, $limit ) {
		$names = $this->taxonomy_names( $taxonomies );
		if ( empty( $names ) ) {
			return array();
		}
		if ( (int) $offset < 0 || (int) $limit < 1 ) {
			return $this->error( implode( ', ', $names ), 'Invalid term pagination', 'Use a non-negative offset and a positive limit.' );
		}
		$terms = get_terms(
			array(
				'taxonomy'   => $names,
				'hide_empty' => false,
				'hierarchical' => false,
				'fields'     => 'all',
				'number'     => (int) $limit,
				'offset'     => (int) $offset,
				'orderby'    => 'term_id',
				'order'      => 'ASC',
			)
		);
		if ( is_wp_error( $terms ) ) {
			return $this->error( implode( ', ', $names ), 'Could not read terms', $terms->get_error_message() );
		}
		$records = array();
		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term || ! in_array( $term->taxonomy, $names, true ) ) {
				return $this->error( implode( ', ', $names ), 'The term query returned an unexpected term', 'Check taxonomy query filters.' );
			}
			$error = $this->collect_term( $term, $records );
			if ( is_wp_error( $error ) ) {
				return $error;
			}
		}
		return $records;
	}

	/**
	 * Normalize and sort names without retaining supplied taxonomy objects.
	 *
	 * @param array $taxonomies Taxonomy names or objects.
	 * @return string[]
	 */
	private function taxonomy_names( $taxonomies ) {
		$names = array();
		foreach ( $taxonomies as $taxonomy ) {
			$name = $taxonomy instanceof WP_Taxonomy ? $taxonomy->name : $taxonomy;
			if ( is_string( $name ) && '' !== $name ) {
				$names[] = $name;
			}
		}
		$names = array_values( array_unique( $names ) );
		sort( $names, SORT_STRING );
		return $names;
	}

	/**
	 * Walk parents iteratively; path tracking detects cycles before cache hits.
	 *
	 * @param WP_Term $term    Starting term.
	 * @param array   $records Per-call records, passed by reference.
	 * @return true|WP_Error
	 */
	private function collect_term( $term, &$records ) {
		$taxonomy = get_taxonomy( $term->taxonomy );
		if ( ! $taxonomy instanceof WP_Taxonomy ) {
			return $this->error( $term->taxonomy, 'Taxonomy is not registered', 'Register it before exporting terms.' );
		}
		$path = array();
		while ( true ) {
			$key = $this->reference_key( $term );
			if ( isset( $path[ $key ] ) ) {
				return $this->error( $taxonomy->name, 'Cyclic term ancestry at term ' . (int) $term->term_id, 'Repair parent relationships before exporting.' );
			}
			if ( isset( $records[ $key ] ) ) {
				return true;
			}
			$path[ $key ] = true;
			$meta        = $this->term_meta( $term );
			if ( is_wp_error( $meta ) ) {
				return $meta;
			}
			$parent = $taxonomy->hierarchical ? (int) $term->parent : 0;
			$records[ $key ] = array(
				'taxonomy'       => $term->taxonomy,
				'source_term_id' => (int) $term->term_id,
				'name'           => $term->name,
				'slug'           => $term->slug,
				'description'    => $term->description,
				'parent'         => $parent ? array( 'taxonomy' => $term->taxonomy, 'source_term_id' => $parent ) : null,
				'meta'           => $meta,
			);
			if ( ! $parent ) {
				return true;
			}
			$term = get_term( $parent, $taxonomy->name );
			if ( is_wp_error( $term ) || ! $term instanceof WP_Term || (int) $term->term_id !== $parent || $term->taxonomy !== $taxonomy->name ) {
				$detail = is_wp_error( $term ) ? $term->get_error_message() : 'The ancestor is missing or belongs to another taxonomy.';
				return $this->error( $taxonomy->name, 'Could not read ancestor term ' . $parent, $detail . ' Repair the parent relationship before exporting.' );
			}
		}
	}

	/**
	 * Read all metadata values, omitting protected and explicitly excluded keys.
	 *
	 * @param WP_Term $term Source term.
	 * @return array|WP_Error
	 */
	private function term_meta( $term ) {
		$meta = get_term_meta( (int) $term->term_id );
		if ( is_wp_error( $meta ) || ! is_array( $meta ) ) {
			$detail = is_wp_error( $meta ) ? $meta->get_error_message() : 'The metadata API did not return an array.';
			return $this->error( $term->taxonomy, 'Could not read metadata for term ' . (int) $term->term_id, $detail );
		}

		/**
		 * Filter additional term metadata keys excluded from exports.
		 *
		 * Protected keys remain excluded regardless of this filter.
		 *
		 * @param string[] $excluded_keys Additional keys to omit. Default empty.
		 * @param string   $taxonomy      Source taxonomy name.
		 */
		$excluded = apply_filters( 'vdi_cpt_acf_export_excluded_term_meta_keys', array(), $term->taxonomy );
		$excluded = is_array( $excluded ) ? $excluded : array();
		foreach ( $meta as $key => $values ) {
			if ( is_protected_meta( (string) $key, 'term' ) || in_array( (string) $key, $excluded, true ) ) {
				unset( $meta[ $key ] );
				continue;
			}
			// Reading all metadata returns cached, possibly serialized values.
			if ( is_array( $values ) ) {
				$values = array_map( 'maybe_unserialize', $values );
				$meta[ $key ] = $values;
			}
			if ( ! is_array( $values ) || ! $this->is_json_value( $values ) ) {
				return $this->error( $term->taxonomy, 'Unsupported metadata "' . $key . '" on term ' . (int) $term->term_id, 'Use scalar/null/array values, or exclude this key with vdi_cpt_acf_export_excluded_term_meta_keys.' );
			}
		}
		if ( false === wp_json_encode( $meta ) ) {
			return $this->error( $term->taxonomy, 'Could not JSON-encode metadata for term ' . (int) $term->term_id, 'Check metadata encoding and nesting, or exclude the affected keys.' );
		}
		return $meta;
	}

	/**
	 * Reject objects/resources and bound nesting, including recursive arrays.
	 *
	 * @param mixed $value Value to validate without changing it.
	 * @param int   $depth Current nesting depth.
	 * @return bool
	 */
	private function is_json_value( $value, $depth = 0 ) {
		if ( $depth > 512 ) {
			return false;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( ! $this->is_json_value( $item, $depth + 1 ) ) {
					return false;
				}
			}
			return true;
		}
		return null === $value || is_string( $value ) || is_bool( $value ) || is_int( $value ) || ( is_float( $value ) && is_finite( $value ) );
	}

	/**
	 * @param WP_Term $term Source term.
	 * @return array
	 */
	private function reference( $term ) {
		return array( 'taxonomy' => $term->taxonomy, 'source_term_id' => (int) $term->term_id );
	}

	/**
	 * @param WP_Term $term Source term.
	 * @return string
	 */
	private function reference_key( $term ) {
		return $term->taxonomy . ':' . (int) $term->term_id;
	}

	/**
	 * @param string $taxonomy Taxonomy name(s).
	 * @param string $message  Failed operation.
	 * @param string $detail   Underlying error or remediation.
	 * @return WP_Error
	 */
	private function error( $taxonomy, $message, $detail ) {
		return new WP_Error(
			'vdi_cpt_acf_export_taxonomy_error',
			sprintf( __( 'Taxonomy "%1$s": %2$s. %3$s', 'vdi-cpt-acf-export' ), $taxonomy, $message, $detail ),
			array( 'taxonomy' => $taxonomy )
		);
	}
}
