<?php
/**
 * REST controller for WP Overnight WCPDF document access keys.
 *
 * Registered under the WooCommerce REST API namespace (wc/v3) so that
 * WooCommerce consumer_key / consumer_secret authentication applies
 * automatically — no extra credentials or configuration required.
 *
 * Namespace : wc/v3
 * Base route: /wcpdf/access-key/{order_id}/{document_type}
 *
 * Full URL example:
 *   GET /wp-json/wc/v3/wcpdf/access-key/237/invoice
 *       ?consumer_key=ck_xxx&consumer_secret=cs_xxx
 *
 * Prerequisites
 * -------------
 * WP Overnight PDF Invoices must be configured with:
 *   WP Admin → WooCommerce → PDF Invoices → Advanced settings
 *   → Document link access type → "Full"
 *
 * In "Full" mode the plugin uses the WooCommerce order key as the access key,
 * which is safe for unauthenticated clients to use because the order key is a
 * unique, random, per-order token already exposed by the WooCommerce REST API.
 *
 * In "Logged in" mode (the plugin default) access keys are WordPress nonces
 * that are only valid for an authenticated browser session — unauthenticated
 * HTTP clients such as mobile apps cannot use them. This endpoint returns
 * HTTP 409 with a clear error when the server is misconfigured that way.
 */

defined( 'ABSPATH' ) || exit;

class WCPDF_REST_Controller extends WP_REST_Controller {

	/** WP Overnight document type slugs accepted by this endpoint. */
	const DOCUMENT_TYPES = [ 'invoice', 'packing-slip', 'credit-note' ];

	public function __construct() {
		// Registering under wc/v3 ensures WooCommerce's determine_current_user
		// hook authenticates consumer_key / consumer_secret requests here.
		$this->namespace = 'wc/v3';
		$this->rest_base = 'wcpdf/access-key';
	}

	// -------------------------------------------------------------------------
	// Route registration
	// -------------------------------------------------------------------------

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
	 *  - The customer who placed the order (matched by WordPress user ID)
	 */
	public function get_access_key_permissions_check( WP_REST_Request $request ): bool|WP_Error {
		$order = wc_get_order( (int) $request['order_id'] );

		if ( ! $order ) {
			return new WP_Error(
				'woocommerce_rest_order_not_found',
				__( 'Order not found.', 'wcpdf-rest-api' ),
				[ 'status' => 404 ]
			);
		}

		if ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) ) {
			return true;
		}

		$user_id = get_current_user_id();
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

	public function get_access_key( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! function_exists( 'wcpdf_get_document' ) || ! function_exists( 'WPO_WCPDF' ) ) {
			return new WP_Error(
				'wcpdf_plugin_missing',
				__( 'PDF Invoices & Packing Slips for WooCommerce is not active.', 'wcpdf-rest-api' ),
				[ 'status' => 500 ]
			);
		}

		// Verify the server is in "full" access mode.
		// In "logged_in" mode, access keys are WordPress nonces that require an
		// active browser session — they cannot be used by unauthenticated clients.
		$access_type = WPO_WCPDF()->endpoint->get_document_link_access_type();
		if ( 'full' !== $access_type ) {
			return new WP_Error(
				'wcpdf_access_mode_incompatible',
				sprintf(
					/* translators: %s: current access mode slug */
					__(
						'WP Overnight PDF Invoices is configured with document link access type "%s". '
						. 'Change it to "full" in WP Admin → WooCommerce → PDF Invoices → Advanced settings '
						. 'so that unauthenticated clients can use the returned access key.',
						'wcpdf-rest-api'
					),
					esc_html( $access_type )
				),
				[ 'status' => 409 ]
			);
		}

		$order         = wc_get_order( (int) $request['order_id'] );
		$document_type = sanitize_key( $request['document_type'] );

		// In "full" mode, WP Overnight uses $order->get_order_key() directly as
		// the access key — it does not generate or store a separate key.
		// We replicate that here rather than loading the document, which avoids
		// triggering invoice number generation as a side-effect.
		if ( $order instanceof WC_Order_Refund ) {
			$parent = wc_get_order( $order->get_parent_id() );
			$access_key = $parent ? $parent->get_order_key() : '';
		} else {
			$access_key = $order->get_order_key();
		}

		if ( empty( $access_key ) ) {
			return new WP_Error(
				'wcpdf_no_order_key',
				__( 'Order key is missing — cannot generate access key.', 'wcpdf-rest-api' ),
				[ 'status' => 500 ]
			);
		}

		return new WP_REST_Response(
			[
				'order_id'      => $order->get_id(),
				'document_type' => $document_type,
				'access_key'    => $access_key,
			],
			200
		);
	}

	// -------------------------------------------------------------------------
	// Schema
	// -------------------------------------------------------------------------

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
					'description' => __( 'WooCommerce order key used as the access key for the generate_wpo_wcpdf admin-ajax action (full access mode only).', 'wcpdf-rest-api' ),
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
				'validate_callback' => static fn( $v ): bool => is_numeric( $v ) && (int) $v > 0,
			],
			'document_type' => [
				'description'       => __( 'Document type: invoice, packing-slip, or credit-note.', 'wcpdf-rest-api' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => static fn( $v ): bool => in_array( $v, WCPDF_REST_Controller::DOCUMENT_TYPES, true ),
			],
		];
	}
}
