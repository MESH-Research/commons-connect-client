<?php
/**
 * Class SearchAPIPreflightTest
 *
 * Tests of the pre-flight reachability guard and the no-hang behaviour of the
 * Search API client when the service is unavailable (issue #1, Options 1 & 3).
 *
 * @package MeshResearch\CCClient
 */

namespace MeshResearch\CCClient\Tests;

use MeshResearch\CCClient\CCClientOptions;
use MeshResearch\CCClient\Search\SearchAPI;
use MeshResearch\CCClient\Search\SearchDocument;

use function MeshResearch\CCClient\Search\Provisioning\search_api_available;

class SearchAPIPreflightTest extends CCCTestCase {

	/**
	 * Build a SearchAPI client pointed at an endpoint where nothing is listening,
	 * with short timeouts so a failure is fast rather than a multi-minute hang.
	 */
	private function unreachable_api(): SearchAPI {
		$options = new CCClientOptions(
			cc_search_key: '12345',
			cc_search_endpoint: 'http://127.0.0.1:9/v1',
			cc_search_admin_key: '12345',
			incremental_provisioning_enabled: false,
			cc_search_timeout: 2,
			cc_search_connect_timeout: 1
		);
		return new SearchAPI( $options );
	}

	public function test_search_api_available_returns_true_when_service_is_up(): void {
		$this->assertTrue( search_api_available( $this->search_api, 'preflight up test' ) );
	}

	public function test_search_api_available_returns_false_when_service_is_unreachable(): void {
		$this->assertFalse( search_api_available( $this->unreachable_api(), 'preflight down test' ) );
	}

	public function test_ping_returns_false_when_service_is_unreachable(): void {
		$this->assertFalse( $this->unreachable_api()->ping( 2 ) );
	}

	public function test_index_returns_false_when_service_is_unreachable(): void {
		$document_json = file_get_contents( __DIR__ . '/test-data/single_test_doc.json' );
		$document = SearchDocument::fromJSON( $document_json );
		$this->assertFalse( $this->unreachable_api()->index( $document ) );
	}
}
