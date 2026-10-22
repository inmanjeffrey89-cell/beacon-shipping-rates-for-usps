# Beacon Shipping Rates for USPS

Live USPS rates at checkout for WooCommerce, built on the current USPS APIs (OAuth2 at `apis.usps.com`), the replacement for the Web Tools API that USPS retired. This is the same code as the plugin on wordpress.org; install it from there so you get updates:

**https://wordpress.org/plugins/beacon-shipping-rates-for-usps/**

You use your own USPS API keys. Nothing is proxied through anyone's server and there is no per-request fee.

## What it does

- Live rates for Ground Advantage, Priority Mail, Priority Mail Express and Media Mail, plus First-Class Package International, Priority Mail International and Priority Mail Express International. Retail or Commercial pricing.
- A guided setup page under WooCommerce, USPS Rates: create a free app in the USPS Developer Portal, paste the Consumer Key and Secret, test the connection, fetch a real rate before you touch a shipping zone. USPS errors are translated into what to do next (for example a 403 means the Prices products are missing from your app).
- Rates that survive the USPS quota. USPS allows 60 calls per hour per API by default. Identical packages are served from a cache, the plugin counts its own calls per clock hour and stops just below the limit, and a stale copy is kept for 7 days and served when USPS errors or the quota is spent.
- A fallback rate so an outage at USPS never shows customers an empty shipping list.
- Packing: one box per order, per product line or per item, with weights and dimensions converted from the store units and lines split at the 70 lb USPS limit.
- Handling markup (fixed or percent), custom method title, handling time, Media Mail limited to a shipping class, tax status.
- Works with shipping zones, the block cart and checkout, and high-performance order storage. Uninstall removes every option and transient the plugin created.

Box packing against real boxes, per-service rules and delivery-date estimates are a separate paid add-on and are not in this repository.

This plugin is not affiliated with, endorsed or sponsored by the United States Postal Service.

## Where things are

- `includes/class-api.php` is the USPS APIs v3 client: an OAuth2 client-credentials token from `/oauth2/v3/token` cached in a transient until shortly before it expires, then `/prices/v3/total-rates/search` or `/international-prices/v3/total-rates/search`, reduced to the cheapest price per mail class.
- `includes/class-cache.php` is the rate cache and the hourly quota guard. The cache key rounds weight to 0.1 lb and dimensions to 0.5 in so near-identical carts share an entry.
- `includes/class-packer.php` turns the cart into USPS package payloads (the three strategies, unit conversion, the 70 lb split).
- `includes/class-shipping-method.php` is the `WC_Shipping_Method`: the zone settings, the fallback, the markup, and the `busr_` filters the add-on hooks into.
- `includes/class-admin.php` is the setup page, the connection and rate tests, and a copy-diagnostics button; `assets/admin.js` drives it.
- `includes/class-settings.php` holds the keys and the sandbox switch (`apis-tem.usps.com`).
- `uninstall.php`.

## Bugs and ideas

Open an issue here or a thread on the [wordpress.org support forum](https://wordpress.org/support/plugin/beacon-shipping-rates-for-usps/). Pull requests are welcome.

## License

GPL-2.0-or-later, see [LICENSE](LICENSE).
