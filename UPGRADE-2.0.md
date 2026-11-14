# DX Pixel Suite 2.0.0 — upgrade and setup

This is a review build for staging. Existing DX settings are retained. New installations enable post views and engaged reads; existing content choices remain unchanged.

## Installation

1. Back up the database and existing DX 1.8.1 ZIP. Install this ZIP using WordPress Plugins → Add New → Upload → replace the existing plugin.
2. Clear page/CDN/object caches and exclude checkout, cart and order-received pages from caching.
3. Keep one owner for each destination. Disable overlapping PixelYourSite/other tracking tags before testing DX. Retain the original feed plugin until the replacement feeds have passed platform ingestion.
4. Connect your banner to `dxpsConsent('granted')` and `dxpsConsent('denied')`. Consent Mode ON now requires consent globally, including unknown geography. This fixes the previous browser/server disagreement. No automatic CMP-category mapping is assumed.
5. Review Platforms and Events & Parameters. GA4 status purchases use Measurement Protocol. In thank-you mode, the optional server-purchase setting replaces DX's browser GA4 purchase. Google Ads conversion labels remain separate.
6. In GTM, use `dxps_ads_purchase` for Ads-only/server-owned purchase events and ensure your GA4 purchase tag does not duplicate server purchases. Existing custom GTM configurations require review. DX cannot inspect their contents.
7. Confirm Action Scheduler or WP-Cron runs. The Feeds tab offers a Continue button for manual batch progress. WooCommerce Scheduled Actions lists the `dx-pixel-suite` group.

## Product feeds

Create feeds under DX Pixel Suite → Product Feeds. Google/Microsoft support XML or TSV; Meta/Pinterest XML, CSV or TSV; generic exports also support JSON. TSV uses `.txt` for Google/Microsoft. Select stock/category/product filters, price tax treatment, fallback brand/category and optional mappings. Each feed uses the store currency and current content language.

Use the product's DX Catalogue panel for brand, GTIN, MPN, Google category and attributes. Variation fields provide exact variant GTIN/MPN/color/size. Native WooCommerce Global Unique ID and selected Product Catalog Feed Pro metadata are recognized; old feed configurations are not imported automatically.

Build, review errors and warnings, and copy the published URL into the platform's scheduled file-fetch data source. Invalid offers are excluded. The previous successful file remains available while rebuilding or after failure. Zero-offer builds fail rather than silently replacing a live catalogue with an empty one. To retire all offers, remove the source in the platform, then delete the DX feed.

Configure country, language, shipping, tax, returns and website verification in the platform. Check ingestion diagnostics and compare event content_ids/item_id with feed IDs. Rebuild feeds after changing the shared product ID format. Category filters match exact category IDs, including separately selected children.

Mappings are JSON with `meta:`, `attribute:`, `taxonomy:` and `literal:` sources. Example:

```json
{"brand":"taxonomy:product_brand","color":"attribute:pa_color","gtin":"meta:_global_unique_id","custom_label_0":"literal:Summer"}
```

Missing identifiers are reported, never fabricated. Set identifier_exists=no only when the manufacturer assigned no identifiers. Pinterest backorders are excluded because its documented availability enum does not include backorder. Apparel validation is an explicit profile switch; it requires size, color, gender and age group.

Files contain public product data only. URLs are opaque but public; do not use private product metadata as feed mappings. Import CSV/TSV as text when opening in spreadsheet software: exact product strings are preserved for machine ingestion, including formula-like prefixes.

## Tracking and matching

- Meta CAPI/browser IDs stay aligned; external browser IDs now use the same SHA-256 representation server-side.
- Google email normalization removes Gmail/Googlemail dots; Meta retains them. Phone normalization distinguishes Meta digits from Google E.164. Available order address fields are included in Google's supported user-data object.
- Matching remains consent-gated. No DOB/gender inference, password/payment-card capture, or sensitive-health audience inference is added. Hashing does not make personal data anonymous.
- Automatic matching supports checkout/account forms and explicit `data-dxps-match`; broader scanning remains opt-in. `data-dxps-ignore` excludes a field/container.
- Blog views, archive views, engaged reads, comments, searches, downloads, leads and custom rules remain available. Read time counts visible, consented time.
- The old direct Google engine has been replaced with one dispatcher. Its duplicate DOM scraping and inferred customer segments are removed. EynnaTracking.push remains; setAttributes is a deprecated no-op. Old EynnaCustomerData APIs are not retained.
- Purchases use authoritative order data and a stored checkout consent snapshot. No snapshot means no server purchase. Successful platforms are not resent when another platform fails. Transient errors retry up to six total attempts with backoff. Review `_dxps_delivery_status` and `_dxps_delivery_attempts` order metadata for troubleshooting.
- Meta accepts events up to seven days old; this build holds GA4 events older than 72 hours. GA4 HTTP success is transport acceptance, not proof of reporting. Refunds have successful-send markers but no automatic retry queue.

## Limits and deployment checks

No live merchant or advertising account was connected in this task. Feed acceptance, image accessibility/dimensions, tax/currency accuracy, policy eligibility, live CAPI deduplication and EMQ improvement require staging/account validation. No plugin can guarantee platform approval or a particular EMQ score.

This is not complete PixelYourSite Pro feature parity: EDD, offline conversions, its premium platform modules, review/local-inventory feeds, WPML/multicurrency orchestration, OAuth/API catalogue submission, arbitrary code mappings and old feed imports are outside this build. Microsoft and Meta profiles need live ingestion validation; Meta's public documentation page did not expose its full specification to the research client.

The local integration tests use SQLite and synthetic products. Production MySQL/MariaDB, real HPOS migrations, large real catalogues, checkout themes, Blocks cart flows, caching and consent-provider integrations need staging checks. HPOS compatibility is declared because order operations use WooCommerce CRUD; this declaration is not a completed production HPOS certification.

Trusted reverse proxies must resolve REMOTE_ADDR correctly or implement `dxps_client_ip`. Optional Cloudflare location enrichment also requires `dxps_trust_proxy_headers` to return true on a properly restricted origin. Arbitrary incoming forwarding headers are not trusted by default.

Deactivation cancels scheduled jobs and retains data/files. No automatic destructive uninstall is provided. Delete feeds in the UI to remove their files. Rollback uses the original DX ZIP and database backup; 2.0 feed options/files may remain until explicitly removed.
