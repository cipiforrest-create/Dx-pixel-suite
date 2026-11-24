# DX Pixel Suite 2.1.0

This update expands the Product Feeds workspace and retains the 2.0 tracking improvements. It does not deploy changes to the live shop or connect to merchant accounts.

## Install and use

1. Back up your site. Upload `dx-pixel-suite-2.1.0.zip` in WordPress Plugins → Add New → Upload and replace the existing DX Pixel Suite plugin. Existing DX settings, feed IDs and published URLs remain in the database. Test on staging first.
2. Open DX Pixel Suite → Product Feeds and edit your existing feed. If an older build is still running during the upgrade, cancel it and start a new build; its last published file remains available.
3. Review Categories & manufacturer identifiers. Map store categories to Google taxonomy IDs; the hair-shop suggestion button fills empty recognized categories (wigs 181; extensions/ponytails/toppers 4057). Product-specific values override category defaults, and explicit field mappings override automatic values.
4. Open Shipping & return policies. Discovery runs automatically and reads up to 1,000 published WordPress pages plus configured WooCommerce terms/privacy pages, and enabled WooCommerce shipping methods. Password-protected pages and drafts are excluded. Rescan after changing store content. Discovery results are cached for 15 minutes.
5. A single matching policy page fills an empty policy URL. Otherwise choose the correct source page. The editor displays the original policy text and shipping conditions. It does not invent return windows, hygiene exclusions, shipping fees or missing policies, execute page content, or crawl external websites.
6. Either keep account-level shipping or enter explicit country/region/service rates. Simple country-wide, numeric, non-taxable flat rates can be copied from WooCommerce. Conditional free shipping, shipping-class formulas, taxable rates and other complex methods are shown as evidence without automatic conversion into a flat rate. Review rates against checkout for all included products. Delivery times are optional whole-day intervals.
7. In Google Merchant Center, create or verify the actual shipping and return policy. The optional return_policy_label must match a policy already configured there. The confirmation checkbox acknowledges this; a label does not upload a policy. Policy URLs are saved for setup, not emitted as unsupported product attributes. Configure returns in the other platforms' account settings.
8. Save the feed, preview a product or variation ID, then Build. Running builds advance while the feed page is open. Recurring unattended updates still require a working WP-Cron/system-cron/Action Scheduler runner. Use the existing published URL in scheduled fetch settings.

## New controls

- Named category filters with optional descendant matching; old saved feeds retain exact matching until enabled.
- Per-store-category Google mappings and hair-category suggestions.
- Visual field mapping with automatic, custom-field, attribute, taxonomy and fixed-value sources.
- Identifier policy: warn, require manufacturer identifiers, or explicitly confirm identifiers were never assigned. Existing and mapped GTIN/MPN data are retained. Never use a shop SKU as an invented manufacturer MPN.
- Destination shipping rows, optional handling/transit ranges, policy source discovery, policy URLs and Google return-policy labels.
- Display-price range, sale-only and virtual-product filters, title prefix/suffix, short/full description choice and additional-image limit.
- Product preview, aggregate issue counts, readable resolution instructions and links to affected products.
- Responsive layout, compatible format selection, and nonce/capability-protected in-page build progress.

XML uses repeated nested shipping elements. Google TSV includes a compound shipping header matching its serialized subfields. Meta uses its shipping column and does not receive Google-only return-policy labels. JSON retains structured shipping arrays. Existing platform format restrictions remain in effect.

Descriptions now retain spaces between HTML paragraphs and line breaks. The browser's “no style information” XML message is informational, not a parsing error. Browser extensions can inject elements into a displayed document; the inspected public feed downloaded from Eynna contained no script elements.

## Validation and remaining setup

Local tests cover WordPress/WooCommerce integration, tracking regression, shipping serialization, policy discovery, identifier/category precedence, visual controls and admin endpoint protection. They do not establish Google/Meta account acceptance or policy eligibility. Missing real identifiers, absent policy pages, unsupported country/currency combinations and account-level problems still need correct merchant data/configuration. No fake attributes are added to suppress warnings.

For broader 2.0 tracking changes and limitations, see UPGRADE-2.0.md. The separate review archive contains source patches, test scripts, evidence and review priorities. Do not install the review archive as a plugin.
