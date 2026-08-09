<?php
/**
 * HTTP transport for the MDS API over the WordPress HTTP API.
 *
 * @package MeuMouse\MDS\SDK
 */

namespace MeuMouse\MDS\SDK\Api;

use MeuMouse\MDS\SDK\Config\Product;
use MeuMouse\MDS\SDK\Security\SignatureVerifier;
use MeuMouse\MDS\SDK\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps wp_remote_* with API-key auth, JSON (de)serialisation, a single retry on
 * transport failure, envelope normalisation and ed25519 signature verification.
 */
final class Client {

	const TIMEOUT = 10;

	/** @var Product */
	private $product;

	/** @var SignatureVerifier */
	private $verifier;

	/** @var Logger */
	private $logger;

	/**
	 * @param Product           $product  Product context.
	 * @param SignatureVerifier $verifier Signature verifier seeded with the public key.
	 * @param Logger            $logger   Debug logger.
	 */
	public function __construct( Product $product, SignatureVerifier $verifier, Logger $logger ) {
		$this->product  = $product;
		$this->verifier = $verifier;
		$this->logger   = $logger;
	}

	/**
	 * POST a JSON body.
	 *
	 * @param string $path              API path, e.g. "/v2/licenses/activate".
	 * @param array  $body              Request payload.
	 * @param bool   $require_signature Reject the response unless validly signed.
	 * @return ApiResponse
	 *
	 * @throws ApiException
	 */
	public function post( $path, array $body, $require_signature = true ) {
		/**
		 * Filter the outbound request payload.
		 *
		 * Outbound only, by design: there is deliberately no filter on the
		 * response, on `$require_signature` or on the verifier, so no hook can
		 * weaken the ed25519 guarantee.
		 *
		 * @param array   $body    Request payload.
		 * @param string  $path    API path, e.g. "/v2/update-check".
		 * @param Product $product Product context.
		 */
		$filtered = apply_filters( $this->product->key( 'request_body' ), $body, $path, $this->product );

		if ( is_array( $filtered ) ) {
			$body = $filtered;
		}

		return $this->request(
			'POST',
			$path,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
			),
			$require_signature
		);
	}

	/**
	 * GET with a query string.
	 *
	 * @param string $path              API path.
	 * @param array  $query             Query args.
	 * @param bool   $require_signature Reject the response unless validly signed.
	 * @return ApiResponse
	 *
	 * @throws ApiException
	 */
	public function get( $path, array $query = array(), $require_signature = true ) {
		$path = empty( $query ) ? $path : $path . '?' . http_build_query( $query );

		return $this->request( 'GET', $path, array(), $require_signature );
	}

	/**
	 * Perform a request with one retry on transport failure.
	 *
	 * @param string $method            HTTP method.
	 * @param string $path              API path.
	 * @param array  $args              Extra wp_remote_request args.
	 * @param bool   $require_signature Whether a valid signature is mandatory.
	 * @return ApiResponse
	 *
	 * @throws ApiException
	 */
	private function request( $method, $path, array $args, $require_signature ) {
		$url = $this->product->api_base_url() . $path;

		$defaults = array(
			'method'     => $method,
			'timeout'    => self::TIMEOUT,
			'sslverify'  => true,
			'user-agent' => $this->user_agent(),
			'headers'    => array(),
		);

		$args            = array_replace_recursive( $defaults, $args );
		$args['headers'] = array_merge(
			$args['headers'],
			array(
				'X-Api-Key' => $this->product->api_key(),
				'Accept'    => 'application/json',
			)
		);

		$response = wp_remote_request( $url, $args );

		// One retry on transport errors only.
		if ( is_wp_error( $response ) ) {
			$response = wp_remote_request( $url, $args );
		}

		if ( is_wp_error( $response ) ) {
			$this->logger->error( 'api.transport', array( 'path' => $path, 'error' => $response->get_error_message() ) );

			throw new ApiException( $response->get_error_message(), 'TRANSPORT_ERROR', 0, true );
		}

		$status   = (int) wp_remote_retrieve_response_code( $response );
		$raw_body = (string) wp_remote_retrieve_body( $response );
		$headers  = wp_remote_retrieve_headers( $response );
		$headers  = is_object( $headers ) && method_exists( $headers, 'getAll' ) ? $headers->getAll() : (array) $headers;

		$decoded = json_decode( $raw_body, true );

		if ( ! is_array( $decoded ) ) {
			throw new ApiException( 'Malformed API response.', 'MALFORMED_RESPONSE', $status );
		}

		// Error envelope: { success:false, error:{ code, message } }. Never signed.
		if ( empty( $decoded['success'] ) ) {
			$error   = isset( $decoded['error'] ) && is_array( $decoded['error'] ) ? $decoded['error'] : array();
			$code    = isset( $error['code'] ) ? (string) $error['code'] : 'API_ERROR';
			$message = isset( $error['message'] ) ? (string) $error['message'] : 'Unexpected API error.';

			$this->logger->warning( 'api.error', array( 'path' => $path, 'code' => $code, 'status' => $status ) );

			throw new ApiException( $message, $code, $status );
		}

		$data   = isset( $decoded['data'] ) && is_array( $decoded['data'] ) ? $decoded['data'] : array();
		$signed = $this->verifier->verify( $raw_body, $headers );

		if ( $require_signature && ! $signed ) {
			// A trusted response MUST be signed. Refuse to act on unsigned data
			// for license-critical calls — this is the anti-"nulled" guarantee.
			$this->logger->error( 'api.unsigned', array( 'path' => $path, 'status' => $status ) );

			throw new ApiException(
				'Response signature missing or invalid.',
				'INVALID_SIGNATURE',
				$status
			);
		}

		$this->logger->step( 'api.ok', array( 'path' => $path, 'status' => $status, 'signed' => $signed ) );

		return new ApiResponse( $data, $signed, $status );
	}

	/**
	 * Build a descriptive User-Agent string.
	 *
	 * @return string
	 */
	private function user_agent() {
		return sprintf(
			'MDS-SDK/%s; %s/%s; WordPress/%s; PHP/%s',
			\MeuMouse\MDS\SDK\SDK::VERSION,
			$this->product->slug(),
			$this->product->current_version(),
			$GLOBALS['wp_version'] ?? 'unknown',
			PHP_VERSION
		);
	}
}
