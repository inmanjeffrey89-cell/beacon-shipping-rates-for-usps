=== Beacon Shipping Rates for USPS ===
Contributors: beaconevents
Tags: usps, shipping rates, woocommerce shipping, live shipping rates, shipping
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Live USPS rates at checkout that keep working: guided API setup, cached rates that respect the USPS quota, and a fallback so checkout never breaks.

== Description ==

USPS retired its old Web Tools API and moved to the new USPS APIs. Beacon Shipping Rates for USPS is built for the new API from the start — with the two things store owners kept asking for: a setup that doesn't take an afternoon, and rates that don't disappear when USPS has a bad hour.

**What you get, free:**

* **Live USPS rates at checkout** for Ground Advantage, Priority Mail, Priority Mail Express and Media Mail, plus First-Class Package International, Priority Mail International and Priority Mail Express International.
* **A guided setup page** (WooCommerce → USPS Rates) that walks you through getting USPS API keys in about 15 minutes, tests the connection, and fetches a real rate before you touch a shipping zone. Every USPS error is translated into what to actually do.
* **Rates that survive the USPS quota.** USPS allows 60 calls per hour by default. Identical packages are served from cache, the plugin stops calling USPS just below the limit, and a stale copy is kept for a week for when USPS is down.
* **A fallback rate** so an outage at USPS never shows customers an empty shipping list.
* **Sane packing:** one box per order, per product line or per item, with automatic unit conversion and the 70 lb USPS limit respected.
* **Handling markup** (fixed or percent), custom method title, Retail or Commercial pricing.
* Works with shipping zones, the block cart and checkout, and High-Performance Order Storage. Uninstalls clean.

You use your **own** USPS API keys — nothing is proxied through anyone's server and there is no per-request fee.

**Pro** (sold separately): box packing against your real boxes, per-service rules (markup, hide, free over, min/max), per-product service restrictions, flat-rate boxes, and delivery-date estimates at checkout. Nothing in the free plugin moves behind the paywall.

Beacon Shipping Rates for USPS is an independent plugin by Beacon Events. It is not affiliated with, endorsed or sponsored by the United States Postal Service. USPS, Priority Mail, Priority Mail Express, USPS Ground Advantage and Media Mail are trademarks of the United States Postal Service.

== Installation ==

1. Install and activate the plugin (WooCommerce must be active).
2. Go to **WooCommerce → USPS Rates** and follow steps 1–4: create a free app in the USPS Developer Portal, paste the Consumer Key and Secret, test the connection, fetch a test rate.
3. Go to **WooCommerce → Settings → Shipping**, open a zone, add the **USPS (Beacon)** method and pick your services.

== Frequently Asked Questions ==

= Do I need a USPS business or shipping account? =
For rates you only need a free USPS Developer Portal app. Retail prices need nothing more. Commercial prices apply if you have a USPS commercial pricing agreement.

= Why are the rates different from usps.com? =
usps.com shows Retail prices by default; if your store is set to Commercial you'll see commercial prices, and vice versa. Change it on the setup page.

= What happens when USPS is down or my hourly quota runs out? =
Cached rates are used first (fresh for the cache period, stale for a week), then the fallback rate you set in the method. Customers never see an empty shipping list unless you turn the fallback off.

= How do I raise the USPS quota? =
Submit a service request on the USPS Developer Portal "Email Us" page. Then raise the hourly ceiling on the setup page to match.

= Does it print labels? =
No. It quotes rates. Use your usual label tool.

= Does it work with the block checkout? =
Yes, and with the classic checkout.

== External services ==

This plugin connects to the **USPS APIs** (operated by the United States Postal Service) to get shipping rates. It is needed to show live USPS prices at checkout.

* **When:** when a customer's cart needs a shipping quote at cart or checkout (unless a cached rate is used), and when a store admin clicks "Test connection" or "Get rates" on the setup page.
* **What is sent:** to `https://apis.usps.com/oauth2/v3/token` — the store's own USPS Consumer Key and Secret, to obtain an access token. To `https://apis.usps.com/prices/v3/total-rates/search` and `https://apis.usps.com/international-prices/v3/total-rates/search` — the origin ZIP code, the destination ZIP or postal code and country, and package weight and dimensions. No customer names, street addresses, emails or order details are sent.
* If the "USPS test environment" option is enabled, the same requests go to `https://apis-tem.usps.com` instead.
* USPS terms of use: https://about.usps.com/who/legal/terms-of-use.htm
* USPS privacy policy: https://about.usps.com/who/legal/privacy-policy/welcome.htm

== Screenshots ==

1. Guided setup: create your USPS API app and paste the keys.
2. Test the connection and get a real USPS rate before you touch a shipping zone.

== Changelog ==

= 0.1.4 =
* Fix: the setup page rate test now names Library Mail and Media Mail (USPS returns them without a product name).

= 0.1.3 =
* First release: live domestic and international USPS rates, guided setup with connection and rate tests, rate cache + hourly quota guard, fallback rate, packing strategies, handling markup, Media Mail eligibility by shipping class, tax status, handling time, copy-diagnostics, extension hooks for the Pro add-on.
