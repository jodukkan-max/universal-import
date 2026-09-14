# ERP Sync Endpoint Notes

## Overview

The plugin exposes a REST API endpoint at `POST /wp-json/scraper/v1/erp-stock` that allows an external ERP/POS system to push stock and price updates to WooCommerce by SKU.

The endpoint requires an **X-ERP-Key** header for authentication — it is a shared secret key generated on plugin activation and displayed on the plugin admin page.

## When It Triggers

- After each sale in the store POS, the ERP sends `stock` only
- When a price changes in the ERP, the ERP sends `price` only
- Both are sent independently; they are not combined in the same request

## Request Format

### Stock Only (after sale)

```json
{
  "items": [
    { "sku": "6473829103", "stock": 12 },
    { "sku": "9485736210", "stock": 5 }
  ]
}
```

### Price Only (when price changes)

```json
{
  "items": [
    { "sku": "6473829103", "price": 25.50 }
  ]
}
```

### Both (supported but typically not used)

```json
{
  "items": [
    { "sku": "6473829103", "stock": 12, "price": 25.50 }
  ]
}
```

## Response Format

```json
{
  "ok": true,
  "updated": [
    { "sku": "6473829103", "product_id": 123, "new_stock": 12 }
  ],
  "failed": [
    { "sku": "9999999", "reason": "SKU not found." }
  ]
}
```

## How It Works Internally

### Stock Update

```php
$product_id = wc_get_product_id_by_sku($sku);
wc_update_product_stock($product_id, $stock, 'set');
```

- `wc_get_product_id_by_sku()` searches both simple products and variations
- `wc_update_product_stock($id, $qty, 'set')` sets the absolute stock quantity, updates stock status (in stock / out of stock), and fires all standard WooCommerce hooks

### Price Update

```php
$product = wc_get_product($product_id);
$product->set_regular_price(wc_format_decimal($price));
$product->save();
```

- Uses WooCommerce CRUD API (`set_regular_price` + `save`)
- Goes through all WooCommerce validation and hooks

## Dashboard

A "ERP Sync" card is shown on the plugin's admin page with:

- The ERP Key (shared secret) with a regenerate button
- The endpoint URL
- Code examples (cURL, JavaScript fetch, PHP cURL) for both stock and price updates — all pre-filled with the ERP key
- A log table of the last 20 requests showing: Time, SKU, Stock, Price, Product ID, Result
- A "Clear Log" button

## Code Examples

### cURL — Update Stock

```bash
curl -X POST https://example.com/wp-json/scraper/v1/erp-stock \
  -H "Content-Type: application/json" \
  -H "X-ERP-Key: YOUR_ERP_KEY" \
  -d '{"items":[{"sku":"6473829103","stock":12}]}'
```

### cURL — Update Price

```bash
curl -X POST https://example.com/wp-json/scraper/v1/erp-stock \
  -H "Content-Type: application/json" \
  -H "X-ERP-Key: YOUR_ERP_KEY" \
  -d '{"items":[{"sku":"6473829103","price":25.50}]}'
```

### JavaScript fetch — Update Stock

```js
fetch("https://example.com/wp-json/scraper/v1/erp-stock", {
    method: "POST",
    headers: {
        "Content-Type": "application/json",
        "X-ERP-Key": "YOUR_ERP_KEY"
    },
    body: JSON.stringify({
        items: [
            { sku: "6473829103", stock: 12 },
            { sku: "9485736210", stock: 5 }
        ]
    })
});
```

### JavaScript fetch — Update Price

```js
fetch("https://example.com/wp-json/scraper/v1/erp-stock", {
    method: "POST",
    headers: {
        "Content-Type": "application/json",
        "X-ERP-Key": "YOUR_ERP_KEY"
    },
    body: JSON.stringify({
        items: [
            { sku: "6473829103", price: 25.50 }
        ]
    })
});
```

### PHP cURL — Update Stock

```php
$payload = json_encode([
    "items" => [
        ["sku" => "6473829103", "stock" => 12],
        ["sku" => "9485736210", "stock" => 5],
    ]
]);

$ch = curl_init("https://example.com/wp-json/scraper/v1/erp-stock");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "X-ERP-Key: YOUR_ERP_KEY"
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
curl_close($ch);
```

### PHP cURL — Update Price

```php
$payload = json_encode([
    "items" => [
        ["sku" => "6473829103", "price" => 25.50],
    ]
]);

$ch = curl_init("https://example.com/wp-json/scraper/v1/erp-stock");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "X-ERP-Key: YOUR_ERP_KEY"
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
curl_close($ch);
```

## Relevant Files

| File | Purpose |
|---|---|
| `rey-swatches-import.php` | Requires and instantiates `Rsi_Erp_Endpoint` in `rsi_init()` |
| `includes/class-erp-endpoint.php` | Registers the REST route, handles `handle_sync()`, manages the log |
| `includes/class-admin-page.php` | Renders the dashboard card with endpoint URL, code examples, and log table |

## Log

The log is stored in the `rsi_erp_stock_log` WordPress option, keeping the last 20 entries. Each entry contains:

- `time` — when the request was received
- `sku` — the SKU sent
- `stock` — the stock value (0 if not provided)
- `price` — the price value (null if not provided)
- `product_id` — the resolved WooCommerce product ID (0 if not found)
- `result` — one of: `updated stock`, `updated price`, `updated both`, `not found`, `missing fields`
