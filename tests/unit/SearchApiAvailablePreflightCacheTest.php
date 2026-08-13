<?php
/**
 * Tests of per-request caching in the search_api_available() pre-flight guard.
 *
 * A single WordPress request can fire several incremental provisioners (site,
 * post, discussion) and each performs a pre-flight reachability check. When
 * the Search API is unavailable, every check costs the full pre-flight
 * timeout, so the result must be cached for the remainder of the request:
 * the observable behaviour under test is that repeated availability checks
 * cost at most one network round-trip per endpoint.
 *
 * @package MeshResearch\CCClient
 */

namespace MeshResearch\CCClient\Tests\Unit;

use MeshResearch\CCClient\CCClientOptions;
use MeshResearch\CCClient\Search\SearchAPI;
use PHPUnit\Framework\TestCase;

use function MeshResearch\CCClient\Search\Provisioning\search_api_available;
use function MeshResearch\CCClient\Search\Provisioning\reset_search_api_available_cache;

/**
 * A SearchAPI whose ping never touches the network, recording each
 * reachability probe so tests can assert on round-trip cost.
 */
class CountingSearchAPI extends SearchAPI {
	public int $ping_count = 0;

	public function __construct(
		string $endpoint,
		private bool $reachable,
	) {
		parent::__construct( new CCClientOptions(
			cc_search_key: 'test-key',
			cc_search_endpoint: $endpoint,
			cc_search_admin_key: 'test-admin-key',
			incremental_provisioning_enabled: false,
		) );
	}

	public function ping( ?int $timeout = null ): bool {
		$this->ping_count++;
		return $this->reachable;
	}
}

class SearchApiAvailablePreflightCacheTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		reset_search_api_available_cache();
	}

	public function test_unreachable_result_is_cached_for_the_request(): void {
		$api = new CountingSearchAPI( 'http://search-a.invalid/v1', false );

		$this->assertFalse( search_api_available( $api, 'first check' ) );
		$this->assertFalse( search_api_available( $api, 'second check' ) );

		$this->assertSame(
			1,
			$api->ping_count,
			'Repeated pre-flight checks against an unreachable endpoint must cost only one probe per request.'
		);
	}

	public function test_reachable_result_is_cached_for_the_request(): void {
		$api = new CountingSearchAPI( 'http://search-a.invalid/v1', true );

		$this->assertTrue( search_api_available( $api, 'first check' ) );
		$this->assertTrue( search_api_available( $api, 'second check' ) );

		$this->assertSame(
			1,
			$api->ping_count,
			'Repeated pre-flight checks against a reachable endpoint must cost only one probe per request.'
		);
	}

	public function test_cache_is_keyed_by_endpoint(): void {
		$down = new CountingSearchAPI( 'http://search-down.invalid/v1', false );
		$up   = new CountingSearchAPI( 'http://search-up.invalid/v1', true );

		$this->assertFalse( search_api_available( $down, 'down check' ) );
		$this->assertTrue( search_api_available( $up, 'up check' ) );
		$this->assertFalse( search_api_available( $down, 'down check again' ) );
		$this->assertTrue( search_api_available( $up, 'up check again' ) );

		$this->assertSame( 1, $down->ping_count );
		$this->assertSame( 1, $up->ping_count );
	}

	public function test_reset_clears_the_cache(): void {
		$api = new CountingSearchAPI( 'http://search-a.invalid/v1', false );

		$this->assertFalse( search_api_available( $api, 'before reset' ) );
		reset_search_api_available_cache();
		$this->assertFalse( search_api_available( $api, 'after reset' ) );

		$this->assertSame(
			2,
			$api->ping_count,
			'Resetting the cache must allow the endpoint to be probed again.'
		);
	}
}
