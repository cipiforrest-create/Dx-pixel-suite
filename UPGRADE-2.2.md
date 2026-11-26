# DX Pixel Suite 2.2.0

Tracking accuracy, match quality and catalogue coverage. Existing settings, feed IDs and published feed URLs are kept. Test on staging first; nothing in this build was run against a live WordPress site, merchant account or ad account.

## What changes without any setup

- **GA4 attribution.** Earlier builds removed the whole query string from the page address sent to GA4, including `utm_*` and `gclid`, so paid and e-mail sessions could be reported as direct or referral. The path plus campaign and click-ID parameters are now kept; every other parameter is still removed. Expect the channel split in GA4 to shift from the upgrade date.
- **Meta Conversions API** calls Graph API v25.0. v21.0 is no longer listed by Meta. Override with the `dxps_meta_api_version` filter.
- **Relay batching.** The browser groups events fired close together and sends them in one request; the server forwards them in one Conversions API call. Per-event lock rows and "sent" markers are gone for page events (Meta deduplicates on event name + event ID); purchases keep theirs. Pages cached before the upgrade keep working with the old single-event format.
- **Match quality.** The browser ID cookie is created before the first PageView and passed to the first pixel init. The relay response returns the visitor's own hashed matching fields (only when Automatic Advanced Matching is on) and the pixel uses them for later browser events. A 64-character `pbid` cookie left by another plugin is now treated the same way by browser and server.
- **GA4 server purchases** include the checkout IP as `ip_override` (so country and city are reported), item brand, category and variant, and the order discount. Category and variant names containing health-related wording are left out.
- **Feeds.** Meta feeds publish WooCommerce backorders as `available for order` (previously the unsupported value `backorder`) and no longer require an availability date. Products without a description are published with their short description or title and a warning, instead of being excluded. `product_type` is the full path of the most specific category. Colour, size, material and pattern are also read from localised attribute names (Boja, Veličina, Dužina, Farbe ...; extend with the `dxps_feed_attribute_aliases` filter). Meta feeds gain `quantity_to_sell_on_facebook` for stock-managed products.
- **Fresher catalogues.** A price, sale, stock, visibility, name, image or category change marks feeds stale; scheduled feeds are rebuilt at the next hourly check (at most once an hour) rather than at the end of their daily interval. Disable with the `dxps_feed_rebuild_on_change` filter. Manual feeds are unaffected.

A running feed build is stopped by the upgrade (its settings fingerprint changes); start it again. The last published file stays available.

## New options

Platforms tab:

- **Merchant Center ID, feed country and language** - adds `aw_merchant_id` and feed locale to the Google Ads purchase conversion. Product IDs, quantities and prices are sent with the conversion in any case. Use the Merchant Center account that fetches the DX Google feed, so IDs match.
- **Dynamic remarketing** (off) - sends view_item, view_item_list, add_to_cart, view_cart, begin_checkout and purchase to Google Ads with product IDs. Google restricts personalised advertising for some categories, including health-related ones. Check the policy for your catalogue before enabling.
- **Send real page titles to GA4** (off).

Feed editor, Products section:

- **Backorders without an availability date** - exclude and report (default, as before), publish as backorder with your lead time, publish as in stock, or publish as out of stock. The lead time is your statement to the platform; set the real one.
- **Tracking parameters for product links** - for example `utm_source=google&utm_medium=shopping`. `{id}` inserts the product ID.
- **Automatic custom labels** - fills empty `custom_label_0` to `custom_label_4` with price tier, sale state, sales history (bestseller, steady-seller, low-sales, no-sales), new arrival and stock level. Labels set on a product or through a mapping win. Use them to split campaigns and bids. Thresholds: the price-tier field, and the `dxps_feed_sales_tiers` filter (default 30 and 5 sales).

## Order data

Orders now also store `_dxps_utm_last` (last-touch campaign and click IDs) next to `_dxps_utm` (first touch).

## Checks after installing

1. Meta Events Manager → Test events: PageView, ViewContent and AddToCart arrive from browser and server with the same event ID and are deduplicated; `external_id` is present on PageView.
2. GA4 DebugView or Realtime: open the site with `?utm_source=test&utm_medium=check` and confirm the session source.
3. Google Ads → the purchase conversion action → Diagnostics: cart data is received after the first tracked order.
4. Rebuild each feed, read the report, and re-check ingestion diagnostics in each platform.

## Regression checks

`php tests/run.php` covers feed rows, option sanitising, identity and the relay endpoint against stand-ins for WordPress functions. `node tests/browser.js` (needs Playwright) loads the rendered tracking scripts in headless Chromium. They do not replace a staging test with WordPress, WooCommerce, your theme, caching and consent banner.
