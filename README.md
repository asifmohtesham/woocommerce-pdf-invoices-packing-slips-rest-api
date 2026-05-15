# WooCommerce PDF Invoices & Packing Slips REST API

A WordPress plugin that exposes document access keys from [WP Overnight "PDF Invoices & Packing Slips for WooCommerce"](https://wordpress.org/plugins/woocommerce-pdf-invoices-packing-slips/) through the **WooCommerce REST API namespace** (`wc/v3`), enabling mobile and third-party clients to fetch PDF documents without a WordPress session.

---

## How WP Overnight access keys work (v5.12.0+)

WP Overnight supports two **Document link access type** modes (configurable in Advanced settings):

| Mode | access_key value | Requires login? |
|---|---|---|
| `logged_in` *(default)* | WordPress nonce — only valid for the current browser session | **Yes** — `is_user_logged_in()` must be true |
| `full` | `$order->get_order_key()` — the WooCommerce order key | **No** — validated with `hash_equals()` only |

**This plugin only works in `full` mode.** In `logged_in` mode, nonces require an active WordPress session cookie that unauthenticated HTTP clients (mobile apps, APIs) cannot provide. The endpoint returns HTTP 409 if the server is in `logged_in` mode, with a message explaining the required setting change.

---

## Prerequisites

| Dependency | Minimum version |
|---|---|
| WordPress | 5.6 |
| WooCommerce | 5.0 |
| PDF Invoices & Packing Slips for WooCommerce (WP Overnight) | 5.0 |
| PHP | 7.4 |

**Required WordPress setting:**  
`WP Admin → WooCommerce → PDF Invoices → Advanced settings → Document link access type → Full`

---

## Installation

1. Download or clone this repository.
2. Upload the `woocommerce-pdf-invoices-packing-slips-rest-api` folder to `/wp-content/plugins/`.
3. Activate the plugin from **Plugins → Installed Plugins**.
4. Set WP Overnight to **Full** access mode (see above).

---

## REST API

### Endpoint

```
GET /wp-json/wc/v3/wcpdf/access-key/{order_id}/{document_type}
```

Registered under the `wc/v3` namespace so WooCommerce's `determine_current_user` hook authenticates `consumer_key` / `consumer_secret` requests automatically.

| Parameter | Type | Values |
|---|---|---|
| `order_id` | integer | WooCommerce order ID |
| `document_type` | string | `invoice` · `packing-slip` · `credit-note` |

### Authentication

Same **WooCommerce consumer key / consumer secret** used by the core WC REST API:

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

```json
{
  "order_id": 237,
  "document_type": "invoice",
  "access_key": "wc_order_AOwpcAp9erC48"
}
```

Use the `access_key` to construct the PDF download URL:

```
https://example.com/wp-admin/admin-ajax.php
  ?action=generate_wpo_wcpdf
  &document_type=invoice
  &order_ids=237
  &access_key=wc_order_AOwpcAp9erC48
```

### Error responses

| Status | Code | Reason |
|---|---|---|
| 404 | `woocommerce_rest_order_not_found` | Order does not exist |
| 409 | `wcpdf_access_mode_incompatible` | Server is in `logged_in` mode — must switch to `full` |
| 403 | `woocommerce_rest_cannot_view` | Insufficient permissions |
| 500 | `wcpdf_plugin_missing` | WP Overnight plugin not active |

---

## Usage in Flutter (FluxStore / `OrderInvoiceService`)

```dart
// order.orderKey is already returned by the WooCommerce REST API.
// In "full" mode, the access_key IS the order key — you can use it directly
// without calling this plugin endpoint:
final uri = Uri.parse(
  '$base/wp-admin/admin-ajax.php'
  '?action=generate_wpo_wcpdf'
  '&document_type=invoice'
  '&order_ids=${order.id}'
  '&access_key=${order.orderKey}',
);
```

Or via this plugin (decouples client from knowing the access mode):

```dart
final keyUri = Uri.parse(
  '$base/wp-json/wc/v3/wcpdf/access-key/${order.id}/invoice'
  '?consumer_key=${config.consumerKey}'
  '&consumer_secret=${config.consumerSecret}',
);
final res = await client.get(keyUri);
final accessKey = jsonDecode(res.body)['access_key'];
```

---

## License

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html)
