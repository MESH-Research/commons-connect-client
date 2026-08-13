<?php
/**
 * Wrapper for the CC-Search API
 * 
 * @package MeshResearch\CCClient
 */

namespace MeshResearch\CCClient\Search;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

use MeshResearch\CCClient\CCClientOptions;

class SearchAPI {
	const MAX_DOCUMENTS_PER_BULK_INDEX_REQUEST = 100;
	
	private $client;
	private $api_key;
	private $api_url;
	private $admin_api_key;

	/**
	 * @param CCClientOptions $options                The plugin options.
	 * @param bool            $retry_on_connect_error  Whether to retry requests that fail with a
	 *                                                 connection error or a 5xx server error. This is
	 *                                                 appropriate for long-running WP-CLI bulk
	 *                                                 operations, but should be left off (the default)
	 *                                                 for interactive, request-time provisioning so
	 *                                                 that an unreachable or erroring Search API does
	 *                                                 not hang the user's request in retry backoff.
	 * @param int|null        $request_timeout         Overrides the request timeout (in seconds).
	 *                                                 When null, the configured
	 *                                                 CCClientOptions::$cc_search_timeout is used.
	 *                                                 Batch contexts may pass a longer value.
	 */
	public function __construct(
		private CCClientOptions $options,
		private bool $retry_on_connect_error = false,
		private ?int $request_timeout = null,
	) {
		if ( empty($options->cc_search_key ) ) {
			throw new \Exception('API key is required');
		}
		$this->api_key = $options->cc_search_key;
		if ( empty($options->cc_search_endpoint ) ) {
			throw new \Exception('API URL is required');
		}
		$this->api_url = $options->cc_search_endpoint;
		$this->admin_api_key = $options->cc_search_admin_key ?? '';

		$timeout = $this->request_timeout ?? $options->cc_search_timeout;
		$handler_stack = HandlerStack::create(new CurlHandler());
		$handler_stack->push($this->_retryMiddleware());
		$this->client = new Client(
			[
				'handler'         => $handler_stack,
				'connect_timeout' => $options->cc_search_connect_timeout,
				'timeout'         => $timeout,
			]
		);
	}

	/**
	 * The endpoint URL this client is configured against.
	 */
	public function get_endpoint(): string {
		return $this->api_url;
	}

	/**
	 * Ping the API to check if it is up
	 *
	 * @param int|null $timeout Optional per-request timeout (in seconds) for both the connection
	 *                          and the response. Used for fast pre-flight reachability checks so
	 *                          callers can bail out quickly when the Search API is unavailable.
	 */
	public function ping( ?int $timeout = null ): bool {
		$request_options = [];
		if ( $timeout !== null ) {
			$request_options['connect_timeout'] = $timeout;
			$request_options['timeout']         = $timeout;
		}
		try {
			$response = $this->client->request('GET', $this->api_url . '/ping', $request_options);
		} catch ( Exception $e ) {
			return false;
		}
		return $response->getStatusCode() == 200;
	}

	/**
	 * Check if the API key is valid
	 */
	public function check_api_key(): bool {
		try {
			$response = $this->client->request('GET', $this->api_url . '/auth_check', [
				'headers' => [
					'Authorization' => 'Bearer ' . $this->api_key,
					'Content-Type' => 'application/json'
				],
			]);
		} catch ( Exception $e ) {
			return false;
		}
		return $response->getStatusCode() == 200;
	}

	/**
	 * Check if the admin API key is valid
	 */
	public function check_admin_api_key(): bool {
		try {
			$response = $this->client->request('GET', $this->api_url . '/admin_auth_check', [
				'headers' => [
					'Authorization' => 'Bearer ' . $this->admin_api_key,
					'Content-Type' => 'application/json'
				],
			]);
		} catch ( Exception $e ) {
			return false;
		}
		return $response->getStatusCode() == 200;
	}

	/**
	 * Index a document
	 */
	public function index(SearchDocument $document): SearchDocument | false {
		try {
			$response = $this->client->request('POST', $this->api_url . '/documents', [
				'headers' => [
					'Authorization' => 'Bearer ' . $this->api_key,
					'Content-Type' => 'application/json'
				],
				'body' => $document->toJSON()
			]);
		} catch ( Exception $e ) {
			return false;
		}
		if ( $response->getStatusCode() != 200 ) {
			return false;
		}
		$returned_document = SearchDocument::fromJSON($response->getBody());
		return $returned_document;
	}

	/**
	 * Bulk index documents
	 *
	 * @param array $documents An array of SearchDocument objects
	 * @return array An array of SearchDocument objects
	 */
	public function bulk_index(array $documents, bool $show_progress = false): array {
		$document_chunks = array_chunk($documents, self::MAX_DOCUMENTS_PER_BULK_INDEX_REQUEST);
		$returned_documents = [];
		if ( $show_progress && class_exists('WP_CLI') ) {
			\WP_CLI::line('Indexing ' . count($documents) . ' documents in ' . count($document_chunks) . ' chunks...');
		}
		$current_chunk = 0;
		foreach ( $document_chunks as $chunk ) {
			$current_chunk++;
			$documents_json = json_encode($chunk);
			try {
				$response = $this->client->request('POST', $this->api_url . '/documents/bulk', [
					'headers' => [
						'Authorization' => 'Bearer ' . $this->api_key,
						'Content-Type' => 'application/json'
					],
					'body' => $documents_json
				]);
			} catch ( \Exception $e ) {
				if ( $show_progress && class_exists('WP_CLI') ) {
					\WP_CLI::warning("Failed to index document chunk: $current_chunk : " . $e->getMessage());
				}
				continue;
			}
			if ( $response->getStatusCode() != 200 ) {
				if ( $show_progress && class_exists('WP_CLI') ) {
					\WP_CLI::warning("Failed to index document chunk: $current_chunk . Received response code: " . $response->getStatusCode());
				}
				continue;
			}
			$returned_documents = array_merge($returned_documents, SearchDocument::fromJSON($response->getBody()));
			if ( $show_progress && class_exists('WP_CLI') ) {
				\WP_CLI::line('Indexed ' . count($returned_documents) . ' documents');
			}
		}
		if ( $show_progress && class_exists('WP_CLI') ) {
			\WP_CLI::line('Indexing complete');
		}
		return $returned_documents;
	}

	/**
	 * Update a document
	 */
	public function update(SearchDocument $document): bool {
		if ( empty($document->_id) ) {
			throw new \Exception('Document must have an _id to update');
		}
		try {
			$response = $this->client->request('PUT', $this->api_url . '/documents/' . $document->_id, [
				'headers' => [
					'Authorization' => 'Bearer ' . $this->api_key,
					'Content-Type' => 'application/json'
				],
				'body' => $document->toJSON()
			]);
		} catch ( \Exception $e ) {
			return false;
		}
		if ( $response->getStatusCode() != 200 ) {
			return false;
		}
		return true;
	}

	/**
	 * Index a document if it doesn't have an _id, otherwise update it
	 */
	public function index_or_update(SearchDocument $document): SearchDocument | false {
		if ( empty($document->_id) ) {
			return $this->index($document);
		}
		$this->update($document);
		return $document;
	}

	/**
	 * Delete a document
	 */
	public function delete(string $id): bool {
		try {
			$response = $this->client->request('DELETE', $this->api_url . '/documents/' . $id, [
				'headers' => [
					'Authorization' => 'Bearer ' . $this->api_key
				]
			]);
		} catch ( \Exception $e ) {
			return false;
		}
		return $response->getStatusCode() == 200;
	}

	/**
	 * Delete all documents from a node
	 */
	public function delete_node(string $node ): bool {
		try {
			$response = $this->client->request('DELETE', $this->api_url . '/documents?network_node=' . $node, [
				'headers' => [
					'Authorization' => 'Bearer ' . $this->admin_api_key
				]
			]);
		} catch ( \Exception $e ) {
			return false;
		}
		return $response->getStatusCode() == 200;
	}

	/**
	 * Reset the index
	 */
	public function reset_index(): bool {
		try {
			$response = $this->client->request('POST', $this->api_url . '/index', [
				'headers' => [
					'Authorization' => 'Bearer ' . $this->admin_api_key
				]
			]);
		} catch ( \Exception $e ) {
			return false;
		}
		return $response->getStatusCode() == 200;
	}

	/**
	 * Get a document
	 */
	public function get_document(string $id, $fields = []): SearchDocument | false {
		$field_query = '';
		if ( ! empty($fields) ) {
			$field_query = '?fields=' . implode(',', $fields);
		}
		try {
			$response = $this->client->request('GET', $this->api_url . '/documents/' . $id . $field_query);
		} catch ( \Exception $e ) {
			return false;
		}
		if ( $response->getStatusCode() != 200 ) {
			return false;
		}
		$returned_document = SearchDocument::fromJSON($response->getBody());
		return $returned_document;
	}

	/**
	 * Search for documents
	 */
	public function search(SearchParams $params): SearchResult | false {
		$request_url = $this->api_url . '/search?' . $params->toQueryString();
		try {
			$response = $this->client->request('GET', $request_url, [
				'headers' => [
					'Authorization' => 'Bearer ' . $this->api_key,
					'Content-Type' => 'application/json'
				],
			]);
		} catch ( \Exception $e ) {
			return false;
		}
		if ( $response->getStatusCode() != 200 ) {
			return false;
		}
		$result = SearchResult::fromJSON($response->getBody());
		return $result;
	}

	/**
	 * Possibly retry failed requests.
	 *
	 * @see: https://github.com/guzzle/guzzle/issues/1806
	 */
	public function _retryMiddleware(): callable {
		return Middleware::retry(
			function (
				int $retries,
				Request $request,
				Response $response = null,
				Exception $error = null
			) {
				if ($retries >= 5) {
					if ( class_exists('WP_CLI') ) {
						\WP_CLI::warning('Maximum retries reached. Aborting...');
					}
					return false;
				}

				if ($error instanceof ConnectException) {
					// Retrying connection errors is appropriate for long-running batch
					// operations, but in interactive/request-time contexts it multiplies the
					// blocking delay felt by the user, so it is opt-in.
					if ( ! $this->retry_on_connect_error ) {
						return false;
					}
					if ( class_exists('WP_CLI') ) {
						\WP_CLI::warning('Connection error. Retrying...');
					}
					return true;
				}

				if ($response) {
					if ($response->getStatusCode() >= 500) {
						// Like connection errors, server errors are only retried in
						// batch contexts. A gateway with no healthy search backend
						// answers 5xx quickly, and the retry backoff (1+2+3+4+5s)
						// would otherwise block interactive requests for ~15 seconds
						// per call — even the short pre-flight ping.
						if ( ! $this->retry_on_connect_error ) {
							return false;
						}
						if ( class_exists('WP_CLI') ) {
							\WP_CLI::warning('Server error. Retrying...');
						}
						return true;
					}
				}

				if ( $error && class_exists('WP_CLI') ) {
					\WP_CLI::warning('Exception thrown: ' . $error->getMessage());
					\WP_CLI::warning('Unhandled HTTP error. Aborting...');
				}
				return false;
			},
			function (int $retries) {
				return 1000 * $retries;
			}
		);
	}
}