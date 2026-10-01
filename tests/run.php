<?php
/** Run with: php tests/run.php. No WordPress, Composer, or PHPUnit required. */
error_reporting( E_ALL );
set_error_handler( function ( $severity, $message, $file, $line ) {
	if ( error_reporting() & $severity ) { throw new ErrorException( $message, 0, $severity, $file, $line ); }
	return false;
} );
require __DIR__ . '/wp-stubs.php';
require dirname( __DIR__ ) . '/includes/class-taxonomy-export.php';
require dirname( __DIR__ ) . '/includes/class-exporter.php';

$tests = array();
function test_case( $name, $callback ) { $GLOBALS['tests'][ $name ] = $callback; }
function same( $expected, $actual, $message = '' ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . '\nExpected: ' . var_export( $expected, true ) . '\nActual: ' . var_export( $actual, true ) );
	}
}
function check( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
function contains_text( $needle, $actual ) { check( strpos( $actual, $needle ) !== false, 'Missing text: ' . $needle . ' in ' . $actual ); }
function export_bytes( $format, $scope = 'assigned', $fields = array() ) {
	$out = tmpfile();
	check( is_resource( $out ), 'Could not create test temporary stream' );
	try {
		$method = new ReflectionMethod( VDI_CPT_ACF_Export_Exporter::class, 'write_export' );
		// PHP 8.1+ allows invoking private methods without setAccessible().
		if ( PHP_VERSION_ID < 80100 ) { $method->setAccessible( true ); }
		$method->invoke( new VDI_CPT_ACF_Export_Exporter(), $out, 'book', $fields, $format, $scope );
		check( rewind( $out ), 'Could not rewind test stream' );
		$bytes = stream_get_contents( $out );
		check( is_string( $bytes ), 'Could not read test stream' );
		return $bytes;
	} finally { fclose( $out ); }
}
function json_export( $scope = 'assigned', $fields = array() ) {
	return json_decode( export_bytes( 'json', $scope, $fields ), true, 512, JSON_THROW_ON_ERROR );
}
function csv_export( $scope = 'assigned', $fields = array() ) {
	$bytes = export_bytes( 'csv', $scope, $fields );
	same( "\xEF\xBB\xBF", substr( $bytes, 0, 3 ), 'CSV UTF-8 BOM' );
	$stream = tmpfile();
	try {
		fwrite( $stream, substr( $bytes, 3 ) );
		rewind( $stream );
		$rows = array();
		while ( false !== ( $row = fgetcsv( $stream, 0, ',', '"', '' ) ) ) { $rows[] = $row; }
		return $rows;
	} finally { fclose( $stream ); }
}
function term_records( $document ) {
	$records = array();
	foreach ( $document['terms'] as $term ) {
		$key = $term['taxonomy'] . ':' . $term['source_term_id'];
		check( ! isset( $records[ $key ] ), 'Duplicate term record: ' . $key );
		$records[ $key ] = $term;
	}
	return $records;
}
function reference( $taxonomy, $id ) { return array( 'taxonomy' => $taxonomy, 'source_term_id' => $id ); }
function expect_export_error( $format, $fragments, $scope = 'assigned' ) {
	try { export_bytes( $format, $scope ); }
	catch ( RuntimeException $error ) {
		foreach ( $fragments as $fragment ) { contains_text( $fragment, $error->getMessage() ); }
		return;
	}
	throw new RuntimeException( 'Expected export failure, but write_export succeeded' );
}
function hierarchy_fixture() {
	fixture_taxonomy( 'category', array( '_builtin' => true, 'hierarchical' => true ) );
	fixture_taxonomy( 'secret', array( 'public' => false, 'show_ui' => false ) );
	fixture_term( 10, 'category', array( 'name' => 'Unassigned parent' ) );
	fixture_term( 11, 'category', array( 'name' => 'Café, "東京"', 'slug' => 'shared', 'parent' => 10, 'description' => 'Crème, "quoted"' ) );
	fixture_term( 12, 'secret', array( 'name' => 'Private, "名"', 'slug' => 'shared' ) );
	fixture_post( 1 );
	fixture_post( 2 );
	fixture_assign( 1, 'category', 11 );
	fixture_assign( 1, 'category', 11 );
	fixture_assign( 2, 'category', 11 );
	fixture_assign( 1, 'secret', 12 );
}

test_case( 'Discovery includes built-ins/private, excludes internals and unrelated types', function () {
	foreach ( array( 'nav_menu', 'link_category', 'wp_theme', 'wp_template_part', 'wp_pattern_category' ) as $name ) { fixture_taxonomy( $name ); }
	fixture_taxonomy( 'secret', array( 'public' => false ) );
	fixture_taxonomy( 'post_tag', array( '_builtin' => true ) );
	fixture_taxonomy( 'category', array( '_builtin' => true, 'hierarchical' => true ) );
	fixture_taxonomy( 'other', array( 'object_type' => array( 'movie' ) ) );
	fixture_post( 1 );
	$collector = new VDI_CPT_ACF_Export_Taxonomy_Export();
	same( array( 'category', 'post_tag', 'secret' ), array_keys( $collector->get_taxonomies( 'book' ) ) );
	$document = json_export();
	same( array( 'category', 'post_tag', 'secret' ), array_column( $document['taxonomies'], 'name' ) );
	same( true, $document['taxonomies'][0]['builtin'] );
	same( false, $document['taxonomies'][2]['public'] );
	same( array( 'ID', 'Title', 'Status', 'Date', 'Taxonomy: category', 'Taxonomy: post_tag', 'Taxonomy: secret' ), csv_export()[0] );
} );

test_case( 'Definitions are allowlisted and selected-post-type-only', function () {
	fixture_taxonomy( 'category', array(
		'object_type' => array( 'book', 'movie' ), '_builtin' => true, 'hierarchical' => true,
		'labels' => (object) array( 'name' => 'Catégories', 'singular_name' => 'Category', 'callback' => 'secret', 'menu_name' => array( 'unsafe' ) ),
		'rewrite' => array( 'slug' => 'genres', 'with_front' => 0, 'hierarchical' => 1, 'ep_mask' => 3, 'callback' => 'secret' ),
		'cap' => (object) array( 'manage_terms' => 'manage_options' ), 'update_count_callback' => 'secret',
	) );
	fixture_post( 1 );
	$document = json_export();
	same( array(
		'name' => 'category', 'label' => 'Category', 'labels' => array( 'name' => 'Catégories', 'singular_name' => 'Category' ),
		'object_type' => array( 'book' ), 'builtin' => true, 'hierarchical' => true, 'public' => true,
		'show_ui' => true, 'show_in_rest' => true, 'rest_base' => false, 'rest_namespace' => 'wp/v2', 'query_var' => true,
		'rewrite' => array( 'slug' => 'genres', 'with_front' => false, 'hierarchical' => true, 'ep_mask' => 3 ),
	), $document['taxonomies'][0] );
	same( 1, $document['schema_version'] );
	same( 'book', $document['post_type'] );
	same( 'assigned', $document['term_scope'] );
} );

test_case( 'JSON ancestors are records only; direct relationships deduplicate; slugs are taxonomy-scoped', function () {
	hierarchy_fixture();
	$document = json_export();
	$terms = term_records( $document );
	same( 3, count( $terms ) );
	same( array( reference( 'category', 11 ) ), $document['posts'][0]['taxonomies']['category'] );
	same( array( reference( 'secret', 12 ) ), $document['posts'][0]['taxonomies']['secret'] );
	same( array( reference( 'category', 11 ) ), $document['posts'][1]['taxonomies']['category'] );
	same( array(), $document['posts'][1]['taxonomies']['secret'] );
	same( reference( 'category', 10 ), $terms['category:11']['parent'] );
	same( null, $terms['category:10']['parent'] );
	same( 'shared', $terms['category:11']['slug'] );
	same( 'shared', $terms['secret:12']['slug'] );
	same( 'Crème, "quoted"', $terms['category:11']['description'] );
} );

test_case( 'CSV preserves core/ACF columns and term names with Unicode, commas and quotes', function () {
	hierarchy_fixture();
	$GLOBALS['fixture']['posts'][0]->post_title = "Café, \"東京\"\nSecond line";
	$GLOBALS['fixture']['posts'][0]->post_status = 'draft';
	$fields = array(
		array( 'name' => 'summary', 'label' => 'Résumé, "text"', 'type' => 'text' ),
		array( 'name' => 'choices', 'label' => '', 'type' => 'checkbox' ),
		array( 'name' => 'body', 'label' => 'Body', 'type' => 'wysiwyg' ),
		array( 'name' => 'missing', 'type' => 'text' ),
		array( 'name' => 'complex', 'type' => 'text' ),
	);
	$GLOBALS['fixture']['acf'][1] = array( 'summary' => 'Naïve, "quoted"', 'choices' => array( 'a,b', '東京' ), 'body' => '<b>Hello</b> café', 'complex' => array( array( 'nested' ) ) );
	$rows = csv_export( 'assigned', $fields );
	same( array( 'ID', 'Title', 'Status', 'Date', 'Résumé, "text"', 'choices', 'Body', 'missing', 'complex', 'Taxonomy: category', 'Taxonomy: secret' ), $rows[0] );
	same( array( '1', "Café, \"東京\"\nSecond line", 'draft', '2026-09-30 12:34:56', 'Naïve, "quoted"', 'a,b, 東京', 'Hello café', '', '' ), array_slice( $rows[1], 0, 9 ) );
	same( 'Café, "東京"', $rows[1][9] );
	same( 'Private, "名"', $rows[1][10] );
	same( '', $rows[2][10] );
	foreach ( $rows as $row ) { same( count( $rows[0] ), count( $row ), 'CSV column alignment' ); }
	$document = json_export( 'assigned', $fields );
	same( $fields, $document['acf_fields'] );
	same( 1, $document['posts'][0]['ID'] );
	foreach ( array( 'Title' => 1, 'Status' => 2, 'Date' => 3 ) as $key => $column ) { same( $rows[1][$column], $document['posts'][0][$key] ); }
	same( array( 'summary' => 'Naïve, "quoted"', 'choices' => 'a,b, 東京', 'body' => 'Hello café', 'missing' => '', 'complex' => '' ), $document['posts'][0]['acf'] );
	contains_text( '東京', export_bytes( 'json', 'assigned', $fields ) );
} );

test_case( 'CSV joins multiple directly assigned term names and leaves empty cells', function () {
	fixture_taxonomy( 'category', array( 'hierarchical' => true ) );
	fixture_term( 10, 'category', array( 'name' => 'Unassigned parent' ) );
	fixture_term( 11, 'category', array( 'name' => 'News', 'parent' => 10 ) );
	fixture_term( 12, 'category', array( 'name' => 'Events' ) );
	fixture_post( 1 );
	fixture_post( 2 );
	fixture_assign( 1, 'category', 12 );
	fixture_assign( 1, 'category', 11 );
	fixture_assign( 1, 'category', 11 );
	$rows = csv_export();
	same( 'News, Events', $rows[1][4] );
	same( '', $rows[2][4] );
	same( 5, count( $rows[1] ), 'Comma-separated names stay in one CSV column' );
} );

test_case( 'Post pagination beyond 200 deduplicates terms globally, not relationships', function () {
	fixture_taxonomy( 'category', array( 'hierarchical' => true ) );
	fixture_term( 10, 'category' );
	fixture_term( 11, 'category', array( 'parent' => 10 ) );
	for ( $id = 205; $id >= 1; $id-- ) {
		fixture_post( $id );
		fixture_assign( $id, 'category', 11 );
		fixture_assign( $id, 'category', 11 );
	}
	fixture_post( 999, array( 'post_type' => 'movie' ) );
	$document = json_export();
	same( range( 1, 205 ), array_column( $document['posts'], 'ID' ) );
	same( 2, count( term_records( $document ) ) );
	foreach ( $document['posts'] as $post ) { same( array( reference( 'category', 11 ) ), $post['taxonomies']['category'] ); }
	same( array( 1, 2 ), array_column( $GLOBALS['fixture']['queries'], 'paged' ) );
	same( array( 200, 200 ), array_column( $GLOBALS['fixture']['queries'], 'posts_per_page' ) );
	same( range( 1, 200 ), $GLOBALS['fixture']['relationship_queries'][0]['ids'] );
	same( range( 201, 205 ), $GLOBALS['fixture']['relationship_queries'][1]['ids'] );
	$rows = csv_export();
	same( 206, count( $rows ) );
	same( '205', $rows[205][0] );
	foreach ( array_slice( $rows, 1 ) as $row ) { same( $GLOBALS['fixture']['terms']['category:11']->name, $row[4] ); }
} );

foreach ( array( 'assigned', 'all' ) as $scope ) {
	test_case( 'No taxonomies: valid CSV and JSON (' . $scope . ')', function () use ( $scope ) {
		fixture_post( 1 );
		$raw = export_bytes( 'json', $scope );
		$document = json_decode( $raw, false, 512, JSON_THROW_ON_ERROR );
		same( array(), $document->taxonomies );
		same( array(), $document->terms );
		check( $document->posts[0]->taxonomies instanceof stdClass, 'Empty taxonomy relationships must encode as an object' );
		check( $document->posts[0]->acf instanceof stdClass, 'Empty ACF must encode as an object' );
		same( array( array( 'ID', 'Title', 'Status', 'Date' ), array( '1', 'Book 1', 'publish', '2026-09-30 12:34:56' ) ), csv_export( $scope ) );
		same( array(), $GLOBALS['fixture']['relationship_queries'] );
		same( array(), $GLOBALS['fixture']['term_queries'] );
	} );
}

test_case( 'No assignments: assigned scope omits unused terms but emits empty relationships', function () {
	fixture_taxonomy( 'category' );
	fixture_term( 1, 'category' );
	fixture_post( 1 );
	$document = json_export();
	same( array(), $document['terms'] );
	same( array( 'category' => array() ), $document['posts'][0]['taxonomies'] );
	same( '', csv_export()[1][4] );
	same( array(), $GLOBALS['fixture']['term_queries'] );
} );

test_case( 'All scope includes unused/empty terms; CSV remains direct-assignment-only', function () {
	hierarchy_fixture();
	fixture_term( 13, 'category', array( 'name' => 'Unused', 'count' => 0 ) );
	fixture_taxonomy( 'empty' );
	$document = json_export( 'all' );
	same( 'all', $document['term_scope'] );
	same( 4, count( term_records( $document ) ) );
	same( 'Unused', term_records( $document )['category:13']['name'] );
	same( array(), $document['posts'][0]['taxonomies']['empty'] );
	same( csv_export( 'assigned' ), csv_export( 'all' ) );
} );

test_case( 'Empty registered taxonomy with no assignments exports empty terms in all scope', function () {
	fixture_taxonomy( 'category' );
	fixture_post( 1 );
	$document = json_export( 'all' );
	same( array(), $document['terms'] );
	same( array( 'category' => array() ), $document['posts'][0]['taxonomies'] );
	same( 1, count( $GLOBALS['fixture']['term_queries'] ) );
	same( '', csv_export( 'all' )[1][4] );
} );

test_case( 'get_all_terms pagination advances by limit despite extra ancestor records', function () {
	fixture_taxonomy( 'category', array( 'hierarchical' => true ) );
	for ( $id = 1; $id <= 205; $id++ ) { fixture_term( $id, 'category', array( 'parent' => $id === 1 ? 205 : 0 ) ); }
	$collector = new VDI_CPT_ACF_Export_Taxonomy_Export();
	$page = $collector->get_all_terms( array( 'category' ), 0, 200 );
	same( 201, count( $page ), 'Ancestor outside page expands record count' );
	check( isset( $page['category:205'] ), 'Outside-page ancestor included' );
	same( 5, count( $collector->get_all_terms( array( 'category' ), 200, 200 ) ) );
	same( array(), $collector->get_all_terms( array( 'category' ), 400, 200 ) );
	$GLOBALS['fixture']['term_queries'] = array();
	fixture_post( 1 );
	fixture_assign( 1, 'category', 1 );
	$document = json_export( 'all' );
	$ids = array_column( $document['terms'], 'source_term_id' );
	sort( $ids );
	same( range( 1, 205 ), $ids );
	same( 205, count( term_records( $document ) ) );
	same( array( 0, 200, 400 ), array_column( $GLOBALS['fixture']['term_queries'], 'offset' ) );
	same( array( 200, 200, 200 ), array_column( $GLOBALS['fixture']['term_queries'], 'number' ) );
	same( array( reference( 'category', 1 ) ), $document['posts'][0]['taxonomies']['category'] );
	foreach ( array( array( -1, 200 ), array( 0, 0 ) ) as $args ) {
		$error = $collector->get_all_terms( array( 'category' ), $args[0], $args[1] );
		check( is_wp_error( $error ), 'Invalid pagination must fail' );
		contains_text( 'Invalid term pagination', $error->get_error_message() );
	}
} );

test_case( 'Term metadata retains multiple structured values, excludes protected and filtered keys', function () {
	hierarchy_fixture();
	$expected = array(
		'color' => array( 'red', 'blue' ),
		'structured' => array( array( 'nested' => array( 1, true, null, '東京', 1.25 ) ) ),
		'empty' => array(),
	);
	$GLOBALS['fixture']['meta'][11] = $expected + array( '_protected' => array( new stdClass() ), 'omit' => array( new stdClass() ) );
	$GLOBALS['fixture']['filters']['vdi_cpt_acf_export_excluded_term_meta_keys'][] = function ( $keys, $taxonomy ) {
		return $taxonomy === 'category' ? array( 'omit' ) : $keys;
	};
	$GLOBALS['fixture']['meta'][12] = array( 'omit' => array( 'retained in another taxonomy' ) );
	$terms = term_records( json_export() );
	same( $expected, $terms['category:11']['meta'] );
	same( array( 'omit' => array( 'retained in another taxonomy' ) ), $terms['secret:12']['meta'] );
	csv_export();
	$GLOBALS['fixture']['filters']['vdi_cpt_acf_export_excluded_term_meta_keys'] = array( function () { return null; } );
	$GLOBALS['fixture']['meta'][11] = $expected + array( '_protected' => array( new stdClass() ) );
	same( $expected, term_records( json_export() )['category:11']['meta'] );
} );

foreach ( array( 'csv', 'json' ) as $format ) {
	test_case( $format . ': relationship WP_Error propagates with taxonomy context', function () use ( $format ) {
		hierarchy_fixture();
		$GLOBALS['fixture']['object_terms_error'] = new WP_Error( 'db_error', 'Relationship database unavailable' );
		expect_export_error( $format, array( 'Taxonomy "category, secret"', 'Could not read post term relationships', 'Relationship database unavailable' ) );
	} );
	test_case( $format . ': missing ancestor fails instead of silently omitting parent', function () use ( $format ) {
		hierarchy_fixture();
		unset( $GLOBALS['fixture']['terms']['category:10'] );
		expect_export_error( $format, array( 'Taxonomy "category"', 'Could not read ancestor term 10', 'Repair the parent relationship' ) );
	} );
	test_case( $format . ': ancestor WP_Error retains underlying detail', function () use ( $format ) {
		hierarchy_fixture();
		$GLOBALS['fixture']['ancestor_errors']['category:10'] = new WP_Error( 'db_error', 'Ancestor database unavailable' );
		expect_export_error( $format, array( 'Could not read ancestor term 10', 'Ancestor database unavailable' ) );
	} );
	test_case( $format . ': cyclic ancestry fails before cached records can mask it', function () use ( $format ) {
		hierarchy_fixture();
		$GLOBALS['fixture']['terms']['category:10']->parent = 11;
		expect_export_error( $format, array( 'Taxonomy "category"', 'Cyclic term ancestry', 'Repair parent relationships' ) );
	} );
	test_case( $format . ': self-parent ancestry fails', function () use ( $format ) {
		hierarchy_fixture();
		$GLOBALS['fixture']['terms']['category:11']->parent = 11;
		expect_export_error( $format, array( 'Cyclic term ancestry at term 11' ) );
	} );
	test_case( $format . ': unsupported unexcluded metadata fails actionably', function () use ( $format ) {
		hierarchy_fixture();
		$GLOBALS['fixture']['meta'][11] = array( 'unsafe' => array( new stdClass() ) );
		expect_export_error( $format, array( 'Unsupported metadata "unsafe" on term 11', 'vdi_cpt_acf_export_excluded_term_meta_keys' ) );
	} );
}

test_case( 'Serialized metadata cache values decode structured multi-values without losing types', function () {
	hierarchy_fixture();
	$first = array( 'name' => 'Café, "東京"', 'nested' => array( 1, true, null, 1.25 ) );
	$second = array( 'items' => array( 'red', 'blue' ), 'literal' => serialize( array( 'keep nested string' ) ) );
	$expected = array(
		'structured' => array( $first, $second ),
		'mixed' => array( 'plain, 東京', '42', false, null, 7, 1.25, 'quoted "name"', array() ),
	);
	// get_term_meta(id) with no key returns raw cached strings, not decoded values.
	$GLOBALS['fixture']['meta'][11] = array(
		'structured' => array( serialize( $first ), serialize( $second ) ),
		'mixed' => array( 'plain, 東京', '42', serialize( false ), serialize( null ), serialize( 7 ), serialize( 1.25 ), serialize( 'quoted "name"' ), serialize( array() ) ),
		'_protected' => array( serialize( new stdClass() ) ),
		'omit' => array( serialize( new stdClass() ) ),
	);
	$GLOBALS['fixture']['filters']['vdi_cpt_acf_export_excluded_term_meta_keys'][] = function ( $keys ) { return array( 'omit' ); };
	foreach ( array( 'assigned', 'all' ) as $scope ) {
		same( $expected, term_records( json_export( $scope ) )['category:11']['meta'] );
		csv_export( $scope );
	}
	// The same cache-value shape also occurs on unused terms in all scope.
	fixture_term( 13, 'category' );
	$GLOBALS['fixture']['meta'][13] = array( 'structured' => array( serialize( $first ), serialize( $second ) ) );
	same( array( 'structured' => array( $first, $second ) ), term_records( json_export( 'all' ) )['category:13']['meta'] );
	$GLOBALS['fixture']['meta'][11]['unsafe'] = array( serialize( new stdClass() ) );
	foreach ( array( 'csv', 'json' ) as $format ) {
		expect_export_error( $format, array( 'Unsupported metadata "unsafe" on term 11', 'vdi_cpt_acf_export_excluded_term_meta_keys' ) );
	}
} );

test_case( 'All scope get_terms errors are not successful-looking JSON', function () {
	fixture_taxonomy( 'category' );
	fixture_post( 1 );
	$GLOBALS['fixture']['get_terms_error'] = new WP_Error( 'db_error', 'Term database unavailable' );
	expect_export_error( 'json', array( 'Taxonomy "category"', 'Could not read terms', 'Term database unavailable' ), 'all' );
} );

test_case( 'Empty post stream produces well-formed outputs through write_export', function () {
	fixture_taxonomy( 'category' );
	same( array(), json_export()['posts'] );
	same( array( array( 'ID', 'Title', 'Status', 'Date', 'Taxonomy: category' ) ), csv_export() );
} );

$failed = 0;
foreach ( $tests as $name => $callback ) {
	reset_fixture();
	try {
		$callback();
		fwrite( STDOUT, 'PASS ' . $name . PHP_EOL );
	} catch ( Throwable $error ) {
		$failed++;
		fwrite( STDERR, 'FAIL ' . $name . PHP_EOL . '  ' . get_class( $error ) . ': ' . $error->getMessage() . PHP_EOL );
	}
}
fwrite( STDOUT, sprintf( "\n%d tests: %d passed, %d failed\n", count( $tests ), count( $tests ) - $failed, $failed ) );
exit( $failed ? 1 : 0 );
