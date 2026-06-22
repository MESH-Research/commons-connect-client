<?php
/**
 * Class CCClientOptionsTest
 *
 * Tests of the configurable Search API timeout options (issue #1, Option 1).
 *
 * @package MeshResearch\CCClient
 */

namespace MeshResearch\CCClient\Tests;

use MeshResearch\CCClient\CCClientOptions;

class CCClientOptionsTest extends \WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		// Ensure timeout environment variables do not leak between tests.
		putenv( 'CC_SEARCH_TIMEOUT' );
		putenv( 'CC_SEARCH_CONNECT_TIMEOUT' );
	}

	protected function tearDown(): void {
		putenv( 'CC_SEARCH_TIMEOUT' );
		putenv( 'CC_SEARCH_CONNECT_TIMEOUT' );
		parent::tearDown();
	}

	public function test_default_timeout_values(): void {
		$options = new CCClientOptions(
			cc_search_key: '12345',
			cc_search_endpoint: 'http://example.test/v1'
		);
		$this->assertSame( 5, $options->cc_search_timeout );
		$this->assertSame( 3, $options->cc_search_connect_timeout );
	}

	public function test_constructor_args_override_timeout_defaults(): void {
		$options = new CCClientOptions(
			cc_search_key: '12345',
			cc_search_endpoint: 'http://example.test/v1',
			cc_search_timeout: 9,
			cc_search_connect_timeout: 4
		);
		$this->assertSame( 9, $options->cc_search_timeout );
		$this->assertSame( 4, $options->cc_search_connect_timeout );
	}

	public function test_environment_variables_override_timeouts(): void {
		putenv( 'CC_SEARCH_TIMEOUT=7' );
		putenv( 'CC_SEARCH_CONNECT_TIMEOUT=2' );
		$options = new CCClientOptions(
			cc_search_key: '12345',
			cc_search_endpoint: 'http://example.test/v1'
		);
		$this->assertSame( 7, $options->cc_search_timeout );
		$this->assertSame( 2, $options->cc_search_connect_timeout );
	}
}
