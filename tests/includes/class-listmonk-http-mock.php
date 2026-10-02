<?php
/**
 * Scripted fake Listmonk server for tests, via the pre_http_request filter.
 *
 * @package FullworksGTL
 */

/**
 * Intercepts wp_remote_request() calls and answers from a route table.
 */
class FWGTL_Listmonk_Http_Mock {

	/**
	 * Recorded requests: method, url, path, query, headers, body (decoded JSON), raw (JSON string).
	 *
	 * @var array[]
	 */
	public $requests = array();

	/**
	 * Routes: [ method, path regex, callable|array response ].
	 *
	 * @var array[]
	 */
	private $routes = array();

	/**
	 * Start intercepting.
	 */
	public function __construct() {
		add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );
	}

	/**
	 * Stop intercepting.
	 */
	public function remove() {
		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );
	}

	/**
	 * Register a response.
	 *
	 * @param string         $method   HTTP method.
	 * @param string         $path     Regex matched against the path below /api/.
	 * @param int|callable   $code     Status code, or a callable( array $request ): array{0:int,1:mixed}.
	 * @param mixed          $data     Value wrapped as { "data": ... }.
	 * @param string         $message  Error message for non-2xx.
	 * @return $this
	 */
	public function on( $method, $path, $code, $data = null, $message = '' ) {
		$this->routes[] = array( $method, $path, $code, $data, $message );
		return $this;
	}

	/**
	 * Requests matching a method and path regex.
	 *
	 * @param string $method Method.
	 * @param string $path   Regex.
	 * @return array[]
	 */
	public function calls( $method, $path ) {
		return array_values(
			array_filter(
				$this->requests,
				function ( $r ) use ( $method, $path ) {
					return $r['method'] === $method && preg_match( '#^' . $path . '$#', $r['path'] );
				}
			)
		);
	}

	/**
	 * Filter callback.
	 *
	 * @param false|array $pre  Short-circuit value.
	 * @param array       $args Request args.
	 * @param string      $url  URL.
	 * @return array|WP_Error
	 */
	public function intercept( $pre, $args, $url ) {
		$parts = wp_parse_url( $url );
		$path  = preg_replace( '#^.*?/api/#', '', $parts['path'] );
		$query = array();
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
		}
		$request          = array(
			'method'  => isset( $args['method'] ) ? $args['method'] : 'GET',
			'url'     => $url,
			'path'    => $path,
			'query'   => $query,
			'headers' => isset( $args['headers'] ) ? $args['headers'] : array(),
			'body'    => isset( $args['body'] ) ? json_decode( $args['body'], true ) : null,
			'raw'     => isset( $args['body'] ) ? $args['body'] : '',
		);
		$this->requests[] = $request;

		foreach ( $this->routes as $route ) {
			list( $method, $regex, $code, $data, $message ) = $route;
			if ( $method !== $request['method'] || ! preg_match( '#^' . $regex . '$#', $path ) ) {
				continue;
			}
			if ( is_callable( $code ) ) {
				list( $code, $data ) = call_user_func( $code, $request );
			}
			if ( 'transport' === $code ) {
				return new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' );
			}
			$body = $code >= 200 && $code < 300 ? array( 'data' => $data ) : array( 'message' => $message );
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( $body ),
				'response' => array(
					'code'    => $code,
					'message' => '',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		}

		return new WP_Error( 'unexpected_request', 'Unmocked request: ' . $request['method'] . ' ' . $path );
	}
}
