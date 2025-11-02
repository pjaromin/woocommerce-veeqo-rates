# Veeqo Rates for WooCommerce (Custom)

**Version:** 0.5.0  
**License:** MIT

A lightweight WooCommerce shipping method that returns live rates from the **Veeqo Rate Shopping API**.  
This build **creates a temporary Veeqo order**, **allocates** it to a warehouse, **updates the package** (dimensions/weight), and then calls `GET /shipping/quotes/amazon_shipping_v2` to display quotes at checkout.

> No debug/fake rates are added in this version.

---

## Features

- Per-shipping-zone method that calls Veeqo’s Rate Shopping API
- Auto-creates a Veeqo Order → Allocation → Package update before rating
- Maps quotes into WooCommerce shipping options (cost is converted from cents)
- Filters:
  - `veeqo_rates_label` — customize label text (carrier + service by default)
  - `veeqo_rates_woo_rate` — modify Woo rate array (e.g., add markup)
- MIT licensed

## Requirements

- WooCommerce 6.0+ (tested up to 9.2)
- PHP 7.4+
- Veeqo account with API key and product catalog (SKUs must match WooCommerce)
- Channel/Store ID, Warehouse ID (from your Veeqo account)

## Installation

1. Upload the plugin folder to `/wp-content/plugins/` or install the ZIP.
2. Activate the plugin.
3. Go to **WooCommerce → Settings → Shipping → Shipping zones**, add **Veeqo Rates** as a method and configure:
   - **API Key** (`x-api-key`)
   - **Channel / Store ID**
   - **Warehouse ID**
   - **Default package** dimensions (cm) and weight (g) for fallback

## How it works (Checkout)

1. Build a parcel from the cart. If product weights are set, they’re summed; otherwise the defaults are used.
2. Create a Veeqo order with line items resolved by SKU → `sellable_id` lookup.
3. Create an allocation for that order under the selected warehouse.
4. Update allocation **package** with dimensions and weight.
5. Call `GET /shipping/quotes/amazon_shipping_v2?allocation_id=...&from_allocation_package=true`.
6. Map each quote into a WooCommerce rate and render at checkout.

> The orders created for rating are minimal and can be managed in your Veeqo UI/policies.

## Hooks

```php
// Tweak the label (input: full quote object from Veeqo)
add_filter('veeqo_rates_label', function($label, $quote){
    return $label; // e.g., add ETA or your own branding
}, 10, 2);

// Modify the WooCommerce rate (id/label/cost/meta_data)
add_filter('veeqo_rates_woo_rate', function($rate, $quote){
    // Example: add 5% markup
    $rate['cost'] = round($rate['cost'] * 1.05, 2);
    return $rate;
}, 10, 2);
```

## Troubleshooting

- **Missing rates** → Verify SKUs in Woo match Veeqo and that the warehouse has stock.
- **401/403** → Check API key and permissions.
- **404 on quotes** → Ensure you created an allocation and passed `from_allocation_package=true`.
- **Units** → Package dims are in **cm**, weight in **grams**.

## Changelog

See `CHANGELOG.md`.
