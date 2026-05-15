# WooCommerce PDF Invoices & Packing Slips REST API

A WordPress plugin that exposes [WP Overnight "PDF Invoices & Packing Slips for WooCommerce"](https://wordpress.org/plugins/woocommerce-pdf-invoices-packing-slips/) document access keys through the WooCommerce REST API.

Mobile and third-party clients can fetch PDF documents without requiring a WordPress session or browser cookies.

---

## Requirements

| Dependency | Minimum version |
|---|---|
| WordPress | 5.6 |
| WooCommerce | 5.0 |
| PDF Invoices & Packing Slips for WooCommerce (WP Overnight) | 3.0 |
| PHP | 7.4 |

---

## Installation

1. Download or clone this repository.
2. Upload the `woocommerce-pdf-invoices-packing-slips-rest-api` folder to `/wp-content/plugins/`.
3. Activate the plugin from **Plugins → Installed Plugins** in the WordPress admin.

---

## REST API

### Endpoint

```
GET /wp-json/wpo-wcpdf/v1/access-key/{order_id}/{document_type}
```

| Parameter | Type | Values |
|---|---|---|
| `order_id` | integer | WooCommerce order ID |
| `document_type` | string | `invoice` · `packing-slip` · `credit-note` |

### Authentication

Uses the same **WooCommerce consumer key / consumer secret** mechanism as the core WC REST API — pass them as query-string parameters or via HTTP Basic Auth.

```
?consumer_key=ck_xxxxx&consumer_secret=cs_xxxxx
```

### Access control

| Caller | Allowed? |
|---|---|
| Shop manager / administrator | ✅ All orders |
| Authenticated customer | ✅ Own orders only |
| Unauthenticated | ❌ 401 / 403 |

### Success response — `200 OK`

```json
{
  "order_id": 237,
  "document_type": "invoice",
  "access_key": "e51342071d"
}
```

Use the `access_key` to construct the PDF download URL:

```
https://example.com/wp-admin/admin-ajax.php
  ?action=generate_wpo_wcpdf
  &document_type=invoice
  &order_ids=237
  &access_key=e51342071d
```

### Error responses

| Status | Code | Reason |
|---|---|---|
| 404 | `woocommerce_rest_order_not_found` | Order does not exist |
| 404 | `wcpdf_document_not_generated` | Invoice not yet generated in WC admin |
| 404 | `wcpdf_document_unavailable` | Unsupported document type |
| 403 | `woocommerce_rest_cannot_view` | Insufficient permissions |
| 500 | `wcpdf_plugin_missing` | WP Overnight plugin not active |

---

## Usage in Flutter (FluxStore)

`OrderInvoiceService` calls this endpoint to resolve the access key, then fetches the PDF bytes directly:

```dart
final uri = Uri.parse(
  '$base/wp-json/wpo-wcpdf/v1/access-key/${order.id}/invoice'
  '?consumer_key=${config.consumerKey}'
  '&consumer_secret=${config.consumerSecret}',
);
final res = await client.get(uri);
final accessKey = jsonDecode(res.body)['access_key'];
```

---

## License

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html)
