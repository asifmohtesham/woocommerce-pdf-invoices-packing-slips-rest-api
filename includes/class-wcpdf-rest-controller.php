<?php
/**
 * REST controller — WP Overnight WCPDF PDF proxy.
 *
 * Registered under the WooCommerce REST API namespace (wc/v3) so that
 * WooCommerce consumer_key / consumer_secret authentication applies
 * automatically — no extra credentials or configuration required.
 *
 * Security design
 * ---------------
 * WP Overnight's admin-ajax.php endpoint is never called by the mobile app.
 * The app authenticates once via the WC REST API, this endpoint authorises the
 * request, then fetches the PDF bytes from WP Overnight internally (server-to-
 * server, within the same PHP process).  No anonymous URL, no access_key
 * exposure, no "full" mode required — WP Overnight can stay in "logged_in"
 * mode.
 *
 * Route
 * -----
 * GET /wp-json/wc/v3/wcpdf/document/{order_id}/{document_type}
 *     ?consumer_key=ck_xxx&consumer_secret=cs_xxx
 *
 * Returns the raw PDF bytes with Content-Type: application/pdf.
 */

defined( 'ABSPATH' ) || exit;

class WCPDF_REST_Controller extends WP_REST_Controller {

	/** WP Overnight document type slugs accepted by this endpoint. */
	const DOCUMENT_TYPES = [ 'invoice', 'packing-slip', 'credit-note' ];

	/** PDF bytes captured from WP Overnight, held for the pre-serve hook. */
	private ?string $pdf_bytes    = null;
	private ?string $pdf_filename = null;

	public function __construct() {
		$this->namespace = 'wc/v3';
		$this->rest_base = 'wcpdf/document';
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
					'callback'            => [ $this, 'get_document_pdf' ],
					'permission_callback' => [ $this, 'get_document_pdf_permissions_check' ],
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
	public function get_document_pdf_permissions_check( WP_REST_Request $request ): bool|WP_Error {
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
	// PDF proxy handler
	// -------------------------------------------------------------------------

	/**
	 * Generate (or load) the WP Overnight PDF and stream it as the REST response.
	 *
	 * WP Overnight is called directly inside this PHP process — admin-ajax.php
	 * is never involved.  The PDF bytes are captured with output buffering and
	 * returned via the rest_pre_serve_request filter so that WordPress sends
	 * application/pdf instead of JSON.
	 */
	public function get_document_pdf( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! function_exists( 'wcpdf_get_document' ) ) {
			return new WP_Error(
				'wcpdf_plugin_missing',
				__( 'PDF Invoices & Packing Slips for WooCommerce is not active.', 'wcpdf-rest-api' ),
				[ 'status' => 500 ]
			);
		}

		$order         = wc_get_order( (int) $request['order_id'] );
		$document_type = sanitize_key( $request['document_type'] );

		// Load the document.  Pass false for the third argument so we don't
		// unintentionally trigger invoice-number generation as a side-effect.
		$document = wcpdf_get_document( $document_type, $order );

		if ( ! $document ) {
			return new WP_Error(
				'wcpdf_document_unavailable',
				sprintf(
					/* translators: %s: document type slug */
					__( 'The "%s" document type is not supported or not available for this order.', 'wcpdf-rest-api' ),
					$document_type
				),
				[ 'status' => 404 ]
			);
		}

		if ( ! $document->exists() ) {
			return new WP_Error(
				'wcpdf_document_not_generated',
				sprintf(
					/* translators: %s: document type slug */
					__( 'The "%s" has not been generated for this order yet. Open the order in WooCommerce admin and generate the invoice first.', 'wcpdf-rest-api' ),
					$document_type
				),
				[ 'status' => 404 ]
			);
		}

		// Capture the PDF bytes from WP Overnight's output.
		ob_start();
		$document->output_pdf();
		$pdf = ob_get_clean();

		if ( empty( $pdf ) ) {
			return new WP_Error(
				'wcpdf_pdf_empty',
				__( 'PDF generation returned empty output.', 'wcpdf-rest-api' ),
				[ 'status' => 500 ]
			);
		}

		// Stash bytes for the pre-serve hook and register it.
		$this->pdf_bytes    = $pdf;
		$this->pdf_filename = method_exists( $document, 'get_filename' )
			? $document->get_filename( 'invoice' )
			: ( $document_type . '-' . $order->get_id() . '.pdf' );

		add_filter( 'rest_pre_serve_request', [ $this, 'serve_pdf' ], 10, 4 );

		// WordPress won't send this — the filter above takes over.
		return new WP_REST_Response( null, 200 );
	}

	/**
	 * rest_pre_serve_request callback — outputs PDF bytes and suppresses JSON.
	 *
	 * @param bool             $served  Whether the request has already been served.
	 * @param WP_HTTP_Response $result  Result to send.
	 * @param WP_REST_Request  $request Request used to generate the response.
	 * @param WP_REST_Server   $server  Server instance.
	 */
	public function serve_pdf( bool $served, WP_HTTP_Response $result, WP_REST_Request $request, WP_REST_Server $server ): bool {
		if ( null === $this->pdf_bytes ) {
			return $served;
		}

		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . rawurlencode( $this->pdf_filename ) . '"' );
		header( 'Content-Length: ' . strlen( $this->pdf_bytes ) );
		header( 'Cache-Control: private, no-store' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $this->pdf_bytes;

		$this->pdf_bytes    = null;
		$this->pdf_filename = null;

		return true; // Prevents WP_REST_Server from sending its own output.
	}

	// -------------------------------------------------------------------------
	// Schema
	// -------------------------------------------------------------------------

	public function get_public_item_schema(): array {
		return [
			'$schema' => 'http://json-schema.org/draft-04/schema#',
			'title'   => 'wcpdf-document',
			'type'    => 'string',
			'format'  => 'binary',
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
