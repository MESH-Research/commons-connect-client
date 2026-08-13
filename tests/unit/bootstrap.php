<?php
/**
 * Bootstrap for standalone unit tests (no WordPress, no live Search API).
 *
 * Loads a composer autoloader (the plugin's own vendor directory, or one
 * supplied via the CCC_TEST_AUTOLOAD environment variable for environments
 * where the plugin is consumed as a dependency), registers a PSR-4 autoloader
 * for the plugin sources, and stubs the few WordPress functions the code
 * under test touches.
 *
 * @package MeshResearch\CCClient
 */

$ccc_autoload_candidates = [
	getenv( 'CCC_TEST_AUTOLOAD' ),
	__DIR__ . '/../../vendor/autoload.php',
];
foreach ( $ccc_autoload_candidates as $ccc_autoload_candidate ) {
	if ( $ccc_autoload_candidate && file_exists( $ccc_autoload_candidate ) ) {
		require_once $ccc_autoload_candidate;
		break;
	}
}

// Prepended so that the sources under test always win over any copy of the
// plugin that an external autoloader (CCC_TEST_AUTOLOAD) may also map.
spl_autoload_register( function ( $class ) {
	$prefix = 'MeshResearch\\CCClient\\';
	if ( strpos( $class, $prefix ) !== 0 ) {
		return;
	}
	$path = __DIR__ . '/../../src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
	if ( file_exists( $path ) ) {
		require_once $path;
	}
}, true, true );

// Ensure environment variables do not leak into CCClientOptions during tests.
foreach ( [
	'CC_SEARCH_KEY',
	'CC_SEARCH_ENDPOINT',
	'CC_SEARCH_ADMIN_KEY',
	'CC_INCREMENTAL_PROVISIONING_ENABLED',
	'CC_SEARCH_PAGE_ID',
	'CC_SEARCH_TIMEOUT',
	'CC_SEARCH_CONNECT_TIMEOUT',
] as $ccc_env_var ) {
	putenv( $ccc_env_var );
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return $default;
	}
}

require_once __DIR__ . '/../../src/Search/Provisioning/provisioning_helper_functions.php';
