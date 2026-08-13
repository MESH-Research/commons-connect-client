<?php
/**
 * Tests of the Search API retry middleware.
 *
 * The retry middleware must never introduce blocking retry backoff into
 * interactive (request-time) use: when the Search API answers with a server
 * error, an interactive client has to fail fast so that user-facing requests
 * (saving a forum post, updating a profile) are not stalled for the duration
 * of the retry schedule. Batch clients (WP-CLI bulk indexing) opt in to
 * retries and keep the more forgiving behaviour.
 *
 * @package MeshResearch\CCClient
 */

namespace MeshResearch\CCClient\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use MeshResearch\CCClient\CCClientOptions;
use MeshResearch\CCClient\Search\SearchAPI;
use PHPUnit\Framework\TestCase;

class SearchAPIRetryTest extends TestCase {

	private function make_api( bool $retry_on_connect_error = false ): SearchAPI {
		$options = new CCClientOptions(
			cc_search_key: 'test-key',
			cc_search_endpoint: 'http://search.invalid/v1',
			cc_search_admin_key: 'test-admin-key',
			incremental_provisioning_enabled: false,
		);
		return new SearchAPI( $options, retry_on_connect_error: $retry_on_connect_error );
	}

	/**
	 * Build a client whose transport is a mock queue but whose middleware
	 * stack is composed the same way as the one SearchAPI builds internally,
	 * so retry behaviour is exercised exactly as in production.
	 */
	private function make_client( SearchAPI $api, MockHandler $mock ): Client {
		$stack = HandlerStack::create( $mock );
		$stack->push( $api->_retryMiddleware() );
		return new Client( [
			'handler'     => $stack,
			'http_errors' => false,
		] );
	}

	public function test_interactive_client_does_not_retry_server_errors(): void {
		$mock   = new MockHandler( array_fill( 0, 6, new Response( 503 ) ) );
		$client = $this->make_client( $this->make_api(), $mock );

		$response = $client->request( 'GET', 'http://search.invalid/v1/ping' );

		$this->assertSame( 503, $response->getStatusCode() );
		$this->assertSame(
			5,
			$mock->count(),
			'An interactive client must fail fast on a server error: exactly one request should be made, with no retries.'
		);
	}

	public function test_batch_client_retries_server_errors(): void {
		$mock   = new MockHandler( [ new Response( 503 ), new Response( 200 ) ] );
		$client = $this->make_client( $this->make_api( retry_on_connect_error: true ), $mock );

		$response = $client->request( 'GET', 'http://search.invalid/v1/ping' );

		$this->assertSame(
			200,
			$response->getStatusCode(),
			'A batch client should retry a server error and succeed when the service recovers.'
		);
		$this->assertSame( 0, $mock->count() );
	}
}
