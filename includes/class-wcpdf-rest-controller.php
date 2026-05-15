<?php
/**
 * REST controller for WP Overnight WCPDF document access keys.
 *
 * Namespace : wpo-wcpdf/v1
 * Base route: /access-key/{order_id}/{document_type}
 *
 * Authentication uses the same WooCommerce consumer_key / consumer_secret
 * mechanism as the core WC REST API — no extra credentials needed.
 */

defined( 'ABSPATH' ) || exit;

class WCPDF_REST_Controller extends WP_REST_Controller {

	/**
	 * Supported WP Overnight document type slugs.
	 */
	const DOCUMENT_TYPES = [ 'invoice', 'packing-slip', 'credit-note' ];

	public function __construct() {
		$this->namespace = 'wpo-wcpdf/v1';
		$this->rest_base = 'access-key';
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<order_id>[\d]+)/(?P<document_type>[a-z-]+)',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_access_key' ],
					'permission_callback' => [ $this, 'get_access_key_permissions_check' ],
					'args'                => $this->get_route_args(),
				],
				'schema' => [ $this, 'get_public_item_schema' ],
			]
		);
	}

	// -------------------------------------------------------------------------
	// Permission check
	// -------------------------------------------------------------------------

	/**
	 * Allows:
	 *  - Shop managers / administrators (manage_woocommerce capability)
	 *  - The customer who placed the order (matched by user ID)
	 *
	 * WooCommerce's determine_current_user hook applies to ALL REST routes, so
	 * consumer_key / consumer_secret in the query string authenticate correctly
	 * here without any extra configuration.
	 */
	public function get_access_key_permissions_check( WP_REST_Request $request ): bool|WP_Error {
		$order = wc_get_order( (int) $request['order_id'] );

		if ( ! $order ) {
			// Return false so WordPress issues a 401/403 before we hit the callback.
			return new WP_Error(
				'woocommerce_rest_order_not_found',
				__( 'Order not found.', 'wcpdf-rest-api' ),
				[ 'status' => 404 ]
			);
		}

		$user_id  = get_current_user_id();
		$is_admin = current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );

		if ( $is_admin ) {
			return true;
		}

		// Allow the customer who placed the order.
		if ( $user_id > 0 && (int) $order->get_customer_id() === $user_id ) {
			return true;
		}

		return new WP_Error(
			'woocommerce_rest_cannot_view',
			__( 'You do not have permission to access this document.', 'wcpdf-rest-api' ),
			[ 'status' => rest_authorization_required_code() ]
		);
	}

	// -------------------------------------------------------------------------
	// Endpoint handler
	// -------------------------------------------------------------------------

	/**
	 * Return the access key for a WP Overnight document.
	 */
	public function get_access_key( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! function_exists( 'wcpdf_get_document' ) ) {
			return new WP_Error(
				'wcpdf_plugin_missing',
				__( 'PDF Invoices & Packing Slips for WooCommerce is not active.', 'wcpdf-rest-api' ),
				[ 'status' => 500 ]
			);
		}

		$order         = wc_get_order( (int) $request['order_id'] );
		$document_type = sanitize_key( $request['document_type'] );

		$document = wcpdf_get_document( $document_type, $order );

		if ( ! $document ) {
			return new WP_Error(
				'wcpdf_document_unavailable',
				sprintf(
					/* translators: %s document type slug, e.g. "invoice" */
					__( 'The "%s" document type is not supported or available.', 'wcpdf-rest-api' ),
					$document_type
				),
				[ 'status' => 404 ]
			);
		}

		if ( ! $document->exists() ) {
			return new WP_Error(
				'wcpdf_document_not_generated',
				sprintf(
					/* translators: %s document type slug */
					__( 'The "%s" has not been generated for this order yet.', 'wcpdf-rest-api' ),
					$document_type
				),
				[ 'status' => 404 ]
			);
		}

		$access_key = $document->get_access_key();

		return new WP_REST_Response(
			$this->prepare_item_for_response( [
				'order_id'      => $order->get_id(),
				'document_type' => $document_type,
				'access_key'    => $access_key,
			], $request ),
			200
		);
	}

	// -------------------------------------------------------------------------
	// Schema
	// -------------------------------------------------------------------------

	/**
	 * Prepare the response data — passes through the array directly here since
	 * the shape is already the final representation.
	 *
	 * @param array            $item
	 * @param WP_REST_Request  $request
	 */
	public function prepare_item_for_response( $item, $request ): array {
		return $item;
	}

	public function get_public_item_schema(): array {
		return [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'wcpdf-access-key',
			'type'       => 'object',
			'properties' => [
				'order_id'      => [
					'description' => __( 'WooCommerce order ID.', 'wcpdf-rest-api' ),
					'type'        => 'integer',
					'context'     => [ 'view' ],
					'readonly'    => true,
				],
				'document_type' => [
					'description' => __( 'WP Overnight document type slug.', 'wcpdf-rest-api' ),
					'type'        => 'string',
					'enum'        => self::DOCUMENT_TYPES,
					'context'     => [ 'view' ],
					'readonly'    => true,
				],
				'access_key'    => [
					'description' => __( 'Access key for the generate_wpo_wcpdf admin-ajax action.', 'wcpdf-rest-api' ),
					'type'        => 'string',
					'context'     => [ 'view' ],
					'readonly'    => true,
				],
			],
		];
	}

	// -------------------------------------------------------------------------
	// Route argument definitions
	// -------------------------------------------------------------------------

	private function get_route_args(): array {
		return [
			'order_id'      => [
				'description'       => __( 'WooCommerce order ID.', 'wcpdf-rest-api' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
				'validate_callback' => static function ( $value ): bool {
					return is_numeric( $value ) && (int) $value > 0;
				},
			],
			'document_type' => [
				'description'       => __( 'Document type: invoice, packing-slip, or credit-note.', 'wcpdf-rest-api' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => static function ( $value ): bool {
					return in_array( $value, WCPDF_REST_Controller::DOCUMENT_TYPES, true );
				},
			],
		];
	}
}
