<?php
/** Minimal in-memory WP/ACF API used only by the standalone regression runner. */
define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code, $message, $data = null ) {
		$this->code = $code;
		$this->message = $message;
		$this->data = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}

class WP_Taxonomy {
	public $name;
	public $label;
	public $labels;
	public $object_type = array( 'book' );
	public $_builtin = false;
	public $hierarchical = false;
	public $public = true;
	public $show_ui = true;
	public $show_in_rest = true;
	public $rest_base = false;
	public $rest_namespace = 'wp/v2';
	public $query_var = true;
	public $rewrite = false;
	public $cap;
	public $update_count_callback;
	public function __construct( $name, $args = array() ) {
		$this->name = $name;
		$this->label = ucfirst( $name );
		$this->labels = (object) array( 'name' => $this->label );
		foreach ( $args as $key => $value ) { $this->$key = $value; }
	}
}

class WP_Term {
	public $term_id;
	public $taxonomy;
	public $name;
	public $slug;
	public $description = '';
	public $parent = 0;
	public $count = 0;
	public $object_id;
	public function __construct( $id, $taxonomy, $args = array() ) {
		$this->term_id = $id;
		$this->taxonomy = $taxonomy;
		$this->name = 'Term ' . $id;
		$this->slug = 'term-' . $id;
		foreach ( $args as $key => $value ) { $this->$key = $value; }
	}
}

function reset_fixture() {
	$GLOBALS['fixture'] = array(
		'taxonomies' => array(), 'terms' => array(), 'posts' => array(),
		'assignments' => array(), 'meta' => array(), 'acf' => array(), 'filters' => array(),
		'object_terms_error' => null, 'get_terms_error' => null, 'ancestor_errors' => array(),
		'queries' => array(), 'relationship_queries' => array(), 'term_queries' => array(),
	);
}
function fixture_taxonomy( $name, $args = array() ) {
	$taxonomy = new WP_Taxonomy( $name, $args );
	$GLOBALS['fixture']['taxonomies'][ $name ] = $taxonomy;
	return $taxonomy;
}
function fixture_term( $id, $taxonomy, $args = array() ) {
	$term = new WP_Term( $id, $taxonomy, $args );
	$GLOBALS['fixture']['terms'][ $taxonomy . ':' . $id ] = $term;
	return $term;
}
function fixture_post( $id, $args = array() ) {
	$post = (object) array_merge( array(
		'ID' => $id, 'post_type' => 'book', 'post_title' => 'Book ' . $id,
		'post_status' => 'publish', 'post_date' => '2026-09-30 12:34:56',
	), $args );
	$GLOBALS['fixture']['posts'][] = $post;
	return $post;
}
function fixture_assign( $post_id, $taxonomy, $term_id ) {
	$GLOBALS['fixture']['assignments'][] = array( $post_id, $taxonomy, $term_id );
}
function stub_require( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( 'WP stub contract: ' . $message ); }
}

class WP_Query {
	public $posts;
	public $found_posts;
	public $max_num_pages;
	public function __construct( $args ) {
		$GLOBALS['fixture']['queries'][] = $args;
		stub_require( $args['orderby'] === 'ID' && $args['order'] === 'ASC', 'stable post order' );
		stub_require( $args['post_status'] === 'any', 'all post statuses' );
		stub_require( $args['update_post_term_cache'] === false, 'batch term reads, not implicit term cache' );
		$posts = array_values( array_filter( $GLOBALS['fixture']['posts'], function ( $post ) use ( $args ) {
			return $post->post_type === $args['post_type'];
		} ) );
		usort( $posts, function ( $a, $b ) { return $a->ID <=> $b->ID; } );
		$this->found_posts = count( $posts );
		$this->max_num_pages = (int) ceil( count( $posts ) / $args['posts_per_page'] );
		$this->posts = array_slice( $posts, ( $args['paged'] - 1 ) * $args['posts_per_page'], $args['posts_per_page'] );
	}
	public function have_posts() { return ! empty( $this->posts ); }
}
function get_object_taxonomies( $post_type, $output ) {
	stub_require( $output === 'objects', 'taxonomy object discovery' );
	return array_filter( $GLOBALS['fixture']['taxonomies'], function ( $taxonomy ) use ( $post_type ) {
		return in_array( $post_type, $taxonomy->object_type, true );
	} );
}
function get_taxonomy( $name ) { return $GLOBALS['fixture']['taxonomies'][ $name ] ?? false; }
function wp_get_object_terms( $ids, $names, $args ) {
	$GLOBALS['fixture']['relationship_queries'][] = array( 'ids' => $ids, 'names' => $names, 'args' => $args );
	stub_require( $args === array( 'fields' => 'all_with_object_id', 'orderby' => 'term_id', 'order' => 'ASC' ), 'direct relationship query arguments' );
	if ( $GLOBALS['fixture']['object_terms_error'] ) { return $GLOBALS['fixture']['object_terms_error']; }
	$result = array();
	foreach ( $GLOBALS['fixture']['assignments'] as $assignment ) {
		list( $post_id, $taxonomy, $term_id ) = $assignment;
		if ( in_array( $post_id, $ids, true ) && in_array( $taxonomy, $names, true ) ) {
			$term = clone $GLOBALS['fixture']['terms'][ $taxonomy . ':' . $term_id ];
			$term->object_id = $post_id;
			$result[] = $term;
		}
	}
	usort( $result, function ( $a, $b ) { return $a->term_id <=> $b->term_id; } );
	return $result;
}
function get_term( $id, $taxonomy ) {
	return $GLOBALS['fixture']['ancestor_errors'][ $taxonomy . ':' . $id ]
		?? $GLOBALS['fixture']['terms'][ $taxonomy . ':' . $id ] ?? null;
}
function get_terms( $args ) {
	$GLOBALS['fixture']['term_queries'][] = $args;
	stub_require( $args['hide_empty'] === false && $args['fields'] === 'all', 'include empty terms' );
	stub_require( $args['orderby'] === 'term_id' && $args['order'] === 'ASC', 'stable term pagination' );
		stub_require( isset( $args['hierarchical'] ) && $args['hierarchical'] === false, 'disable hierarchical expansion to enforce pagination' );
	if ( $GLOBALS['fixture']['get_terms_error'] ) { return $GLOBALS['fixture']['get_terms_error']; }
	$terms = array_values( array_filter( $GLOBALS['fixture']['terms'], function ( $term ) use ( $args ) {
		return in_array( $term->taxonomy, $args['taxonomy'], true );
	} ) );
	usort( $terms, function ( $a, $b ) { return $a->term_id <=> $b->term_id; } );
	return array_slice( $terms, $args['offset'], $args['number'] );
}
function get_term_meta( $id ) { return $GLOBALS['fixture']['meta'][ $id ] ?? array(); }
function maybe_unserialize( $value ) {
	// Mirror WP's strict serialized-value detection; leave other cache values intact.
	if ( ! is_string( $value ) ) { return $value; }
	$data = trim( $value );
	if ( $data === 'N;' ) { return null; }
	if ( strlen( $data ) < 4 || $data[1] !== ':' || ! in_array( substr( $data, -1 ), array( ';', '}' ), true ) ) { return $value; }
	$token = $data[0];
	if ( $token === 's' && substr( $data, -2, 1 ) !== '"' ) { return $value; }
	if ( in_array( $token, array( 's', 'a', 'O', 'E' ), true ) && preg_match( '/^' . $token . ':[0-9]+:/s', $data ) ) {
		return @unserialize( $data );
	}
	if ( in_array( $token, array( 'b', 'i', 'd' ), true ) && preg_match( '/^' . $token . ':[0-9.E+-]+;$/', $data ) ) {
		return @unserialize( $data );
	}
	return $value;
}
function is_protected_meta( $key, $type ) {
	stub_require( $type === 'term', 'term metadata protection' );
	return isset( $key[0] ) && $key[0] === '_';
}
function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['fixture']['filters'][ $hook ] ?? array() as $callback ) {
		$value = $callback( $value, ...$args );
	}
	return $value;
}
function get_field( $name, $id ) { return $GLOBALS['fixture']['acf'][ $id ][ $name ] ?? null; }
function wp_strip_all_tags( $value ) { return trim( strip_tags( $value ) ); }
function wp_list_pluck( $items, $key ) { return array_map( function ( $item ) use ( $key ) { return $item->$key; }, $items ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function absint( $value ) { return abs( (int) $value ); }
function __( $message, $domain = null ) { return $message; }
function wp_reset_postdata() {}
