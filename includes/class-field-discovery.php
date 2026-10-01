<?php
/**
 * Discover exportable ACF field definitions for a post type.
 *
 * @package VDI_CPT_ACF_Export
 */

defined( 'ABSPATH' ) || exit;

/**
 * Returns ordered scalar-friendly ACF field defs via field-group APIs.
 */
class VDI_CPT_ACF_Export_Field_Discovery {

	/**
	 * Scalar-friendly ACF field types included in v0 exports.
	 * Checkbox is included and comma-joined by the exporter.
	 *
	 * @var string[]
	 */
	private static $allowed_types = array(
		'text',
		'textarea',
		'number',
		'range',
		'email',
		'url',
		'password',
		'wysiwyg',
		'select',
		'radio',
		'button_group',
		'true_false',
		'date_picker',
		'date_time_picker',
		'time_picker',
		'checkbox',
	);

	/**
	 * Ordered unique exportable ACF field defs for a post type.
	 *
	 * Uses acf_get_field_groups / acf_get_fields filtered by location for
	 * the post type (not from first post). Empty if ACF inactive or no groups.
	 *
	 * @param string $post_type Post type name.
	 * @return array<int, array{name: string, label: string, type: string}>
	 */
	public function get_exportable_fields( $post_type ) {
		$post_type = sanitize_key( $post_type );
		if ( '' === $post_type ) {
			return array();
		}

		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			return array();
		}

		$groups = acf_get_field_groups(
			array(
				'post_type' => $post_type,
			)
		);

		if ( empty( $groups ) || ! is_array( $groups ) ) {
			return array();
		}

		$ordered = array();
		$seen    = array();

		foreach ( $groups as $group ) {
			$fields = acf_get_fields( $group );
			if ( empty( $fields ) || ! is_array( $fields ) ) {
				continue;
			}
			$this->collect_fields( $fields, $ordered, $seen );
		}

		return $ordered;
	}

	/**
	 * Walk field list; keep allowed types; unique by name; stable group order.
	 *
	 * @param array $fields  ACF field arrays.
	 * @param array $ordered Accumulator of defs.
	 * @param array $seen    Names already added (by ref).
	 */
	private function collect_fields( $fields, &$ordered, &$seen ) {
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$type = isset( $field['type'] ) ? $field['type'] : '';
			$name = isset( $field['name'] ) ? $field['name'] : '';

			// Skip layout-only / empty-name fields.
			if ( '' === $name || in_array( $type, array( 'tab', 'accordion', 'message' ), true ) ) {
				continue;
			}

			if ( ! in_array( $type, self::$allowed_types, true ) ) {
				continue;
			}

			if ( isset( $seen[ $name ] ) ) {
				continue;
			}

			$label = isset( $field['label'] ) && '' !== $field['label']
				? $field['label']
				: $name;

			$seen[ $name ] = true;
			$ordered[]     = array(
				'name'  => $name,
				'label' => $label,
				'type'  => $type,
			);
		}
	}

	/**
	 * Whether ACF is loaded with field-group APIs.
	 *
	 * @return bool
	 */
	public function is_acf_available() {
		return function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_get_fields' );
	}
}
