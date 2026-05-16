# WooCommerce PDF Invoices & Packing Slips REST API

A WordPress plugin that proxies WP Overnight "PDF Invoices & Packing Slips for WooCommerce" documents through the **WooCommerce REST API**, enabling authenticated mobile and third-party clients to receive PDF bytes directly — without exposing `admin-ajax.php` to anonymous requests.

---

## Security design

### Why not use `admin-ajax.php` directly?

WP Overnight's PDF endpoint (`admin-ajax.php?action=generate_wpo_wcpdf&...`) has two access modes:

| Mode | access_key | Anonymous access? | Risk |
|---|---|---|---|
| `logged_in` *(default)* | WordPress nonce — valid only in the current browser session | ❌ No | None, but mobile apps can't use nonces |
| `full` | `$order->get_order_key()` — a random but **never-expiring** token | ✅ Yes | Anyone who ever sees a confirmation email can download the invoice forever |

### What this plugin does instead

```
Mobile App  ──[WC consumer_key/secret]──►  /wp-json/wc/v3/wcpdf/document/{id}/invoice
                                               │ auth check (admin or order owner)
                                               │ wcpdf_get_document() — internal PHP call
                                               │ ob_start() / output_pdf() / ob_get_clean()
                                               ▼
                                           PDF bytes  ──► app
```

- `admin-ajax.php` is **never called** from the mobile app.
- WP Overnight stays in **`logged_in` mode** (most secure).
- All access is authenticated via WooCommerce consumer key / secret.
- Admins can access any order's PDF; customers can only access their own.

---

## Requirements

| Dependency | Minimum version |
|---|---|
| WordPress | 5.6 |
| WooCommerce | 5.0 |
| PDF Invoices & Packing Slips for WooCommerce (WP Overnight) | 5.0 |
| PHP | 7.4 |

No change to WP Overnight's access mode setting is required.  
The invoice **must have been generated at least once** in WooCommerce admin before this endpoint can serve it (`$document->exists()` must be true).

---

## Installation

1. Clone or download this repository.
2. Upload the `woocommerce-pdf-invoices-packing-slips-rest-api` folder to `/wp-content/plugins/`.
3. Activate from **Plugins → Installed Plugins**.

---

## REST API

### Endpoint

```
GET /wp-json/wc/v3/wcpdf/document/{order_id}/{document_type}
    ?consumer_key=ck_xxx&consumer_secret=cs_xxx
```

Returns the PDF as `application/pdf` binary (not JSON).

| Parameter | Type | Values |
|---|---|---|
| `order_id` | integer | WooCommerce order ID |
| `document_type` | string | `invoice` · `packing-slip` · `credit-note` |

### Authentication

Uses the same **WooCommerce consumer key / consumer secret** as the core WC REST API.

```
?consumer_key=ck_xxxxx&consumer_secret=cs_xxxxx
```

### Access control

| Caller | Allowed? |
|---|---|
| Shop manager / administrator | ✅ All orders |
| Authenticated customer | ✅ Own orders only |
| Unauthenticated | ❌ 401 |

### Success response — `200 OK`

```
Content-Type: application/pdf
Content-Disposition: inline; filename="invoice-237.pdf"
Content-Length: <bytes>

<binary PDF data>
```

### Error responses

| Status | Code | Reason |
|---|---|---|
| 404 | `woocommerce_rest_order_not_found` | Order does not exist |
| 404 | `wcpdf_document_not_generated` | Invoice not yet generated in WC admin |
| 404 | `wcpdf_document_unavailable` | Unsupported document type |
| 403 | `woocommerce_rest_cannot_view` | Insufficient permissions |
| 500 | `wcpdf_plugin_missing` | WP Overnight plugin not active |
| 500 | `wcpdf_pdf_empty` | WP Overnight returned empty output |

---

## Usage in Flutter (FluxStore / `OrderInvoiceService`)

```dart
Future<Uint8List> fetchInvoicePdf(Order order) async {
  final config = ServerConfig();
  final base   = config.url.replaceAll(RegExp(r'/$'), '');
  final uri    = Uri.parse(
    '$base/wp-json/wc/v3/wcpdf/document/${order.id}/invoice'
    '?consumer_key=${config.consumerKey}'
    '&consumer_secret=${config.consumerSecret}',
  );
  final response = await client.get(uri);
  if (response.statusCode == 200) return response.bodyBytes;
  // handle errors ...
}
```

No `access_key` handling, no `admin-ajax.php` URL, no mode-specific logic.

---

## License

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html)
