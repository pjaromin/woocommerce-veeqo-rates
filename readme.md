# WooCommerce Veeqo Rates (Custom)

Adds live shipping options from **Veeqo** to WooCommerce checkout using the **Rate Shopping API** (Amazon Shipping v2).

> ⚠️ Current behavior: Veeqo's `/shipping/quotes/amazon_shipping_v2` endpoint typically requires an **`allocation_id`** for a Veeqo order allocation. The plugin includes a temporary `allocation_id` setting for testing. Roadmap includes creating/updating a Veeqo allocation from the Woo cart automatically.

## Features
- Live quotes from Veeqo Rate Shopping API
- Uses modern `x-api-key` auth
- Optional **Debug mode** (adds a $4.99 probe + verbose Woo logs)
- Lightweight, zone-aware shipping method with per‑instance settings
- Simple filters to customize labels and Woo rate arrays

## Requirements
- PHP 7.4+
- WordPress 6.0+
- WooCommerce 8.x/9.x
- A valid Veeqo **API key** with access to Rate Shopping

## Installation
1. Download the ZIP from GitHub or this build and upload via **Plugins → Add New → Upload Plugin**, or copy the plugin folder to `wp-content/plugins/`.
2. Activate **Veeqo Rates for WooCommerce (Custom)**.
3. Go to **WooCommerce → Settings → Shipping → Shipping Zones**, add **Veeqo Rates** to your zone.
4. Enter your **Veeqo API key**.
5. (For initial testing) paste a known **allocation_id** for a Veeqo order allocation.
6. Turn **Debug mode OFF** for live use; ON adds a fixed $4.99 probe to confirm the method runs.

## Getting an `allocation_id`
- In the Veeqo UI: open an order → browser DevTools → **Network** tab → look for an order JSON payload containing `allocations[0].id`.
- Via API: `GET https://api.veeqo.com/orders/{order_id}` with header `x-api-key: YOUR_KEY` → parse `allocations[0].id`.

## Logs
- See **WooCommerce → Status → Logs** and select the latest `veeqo_rates-*.log`.
- Logs include the request URL (without your key), HTTP status, and a trimmed body snippet.

## Filters
```php
/**
 * Filter the label before adding the Woo rate.
 * @param string $label Suggested label.
 * @param array  $veeqo_rate Raw Veeqo rate array.
 */
apply_filters( 'veeqo_rates_label', $label, $veeqo_rate );

/**
 * Filter the full Woo rate array before it's added.
 * @param array $woo_rate Woo rate array (id,label,cost,meta_data).
 * @param array $veeqo_rate Raw Veeqo rate array.
 */
apply_filters( 'veeqo_rates_woo_rate', $woo_rate, $veeqo_rate );
```
Example: add a 5% markup
```php
add_filter('veeqo_rates_woo_rate', function($rate){
    $rate['cost'] = round($rate['cost'] * 1.05, 2);
    return $rate;
});
```

## Roadmap
- Create/update a Veeqo **allocation** from the Woo cart automatically, then fetch quotes (no manual `allocation_id`).
- Handling for multiple parcels / custom packaging logic.
- Per‑zone origin address overrides.
- Free‑over‑X and percentage markups in settings.
- Carrier/service mapping & renaming rules.

## Security
- Your API key is stored in Woo settings using a `password` field. Avoid committing secrets to version control.

## License
MIT © ZDL Pro Audio Group
