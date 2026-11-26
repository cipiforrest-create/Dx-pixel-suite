=== DX Pixel Suite ===
Version: 1.9.0
Stable tag: 1.9.0
Requires PHP: 7.4
WooCommerce optional (since 1.8.0). HTTPS and a modern browser recommended.
Custom patched release for Eynna Hair / Digital X.

UPGRADING FROM 1.3.2
1. Back up the site and the current plugin before replacing files.
2. WordPress > Plugins > Add New > Upload Plugin > choose dx-pixel-suite-1.5.4.zip.
3. Choose “Replace current with uploaded” if WordPress offers it. Keep the plugin active.
4. Existing dxps_settings options remain in the database and take precedence over defaults.
   Do not delete settings or uninstall the existing plugin to perform this upgrade.
5. Clear cached HTML after the upgrade. Refresh CDN HTML cache as appropriate.
   Avoid deleting old minified asset files while cached HTML still refers to them.
6. Check the homepage, mobile menu, product/cart/checkout pages and tracking diagnostics.

This ZIP updates plugin code only. It does not publish GTM, activate Meta, change
existing consent settings, install a consent banner or include API credentials.
The matching field names, plugin folder and settings option name are preserved.
Keep only one browser sender per platform. GTM-only means Google dataLayer ownership;
it does not automatically disable DX Meta or Meta tags inside the GTM container.

WHAT'S NEW IN THE PATCHED BUILD
- Correct Meta phone normalization: digit-only SHA-256 inputs with a real country code.
- Validated email/name/address matching and strict hashed-field ingestion.
- Genuine fbp/fbc, external IDs, validated IPv4/IPv6 and user agents; no invented customer data.
- Matching-data hashes transported to the first-party ingest endpoint from eligible forms.
- Completed/valid checkout and account fields only, plus explicitly opted-in forms.
  Opt an eligible form in with data-dxps-match. Exclude fields/forms with data-dxps-ignore.
- No customer matching values inserted into cacheable pixel HTML.
- Short-lived matching storage, explicit withdrawal handling and identity-change isolation.
- Conservative sensitive-context filtering. No Meta free-text search, form text, query
  strings, medical coupons, inferred DOB/gender or lifetime financial profiling.
  This is not a substitute for a full privacy/compliance and catalog-policy review.
- Shared browser/server event IDs, corrected event aliases and bounded relay retries.
- WooCommerce Blocks attribution snapshot hook, additional actual checkout selections
  and supported wishlist signals. Compatibility with a particular third-party wishlist
  plugin should be verified; not every plugin emits the same event signature.
- Correct commerce values/currency/IDs/quantities and numeric GA4 shipping amounts.
- Google dataLayer delivery retained for status-mode purchases; no duplicate DX direct
  Google dispatch in GTM-only mode.
- API acceptance checks and per-destination purchase delivery markers. Failed attempts
  do not become successful-send markers. Legacy sent flags and snapshot-less orders
  are not automatically replayed or bulk-cleared.
- Protected administrator-only, nonce-checked, rate-limited CAPI diagnostic. The new
  “Send test PageView” button does not save settings or enable live Meta tracking.
- API credential fields masked in the admin interface; no secrets included in this ZIP.

TESTING
Open DX Pixel Suite > Platforms. Enter the current Meta Events Manager TEST code,
then click Send test PageView without clicking Save settings. A stored CAPI token is
required. The diagnostic deliberately uses only a temporary identifier and request
IP/user agent, not fake customer names, email, phone, purchase or lead data.
Verify the server test event in Meta Events Manager. Then validate real-event browser /
server event_id matching and deduplication as part of the coordinated live cutover.
A new anonymous PageView may correctly have no known email/phone; those must not be invented.

CONSENT / DESTINATIONS
Existing consent settings are preserved. Explicitly denied marketing consent remains
respected. No cookie banner is added by this package. Meta/GA4 direct sending is governed
by existing destination flags. If those destinations are disabled, DX does not collect
form-matching data for a disabled server destination. Review GTM tags independently.
Use existing dxpsConsent('granted'/'denied') / EynnaConsent.update for integrations.

CHANGELOG
1.5.4: one dispatch per queued event ID; fresh client IDs for cached public view/search
payloads, while preserving supplied purchase IDs and explicit integration IDs. Fixes
double queue execution and cross-visitor deduplication caused by cached PHP IDs.
1.5.3: packaged patched release, complete 1.3.2-compatible folder layout, native test
button, masked credential inputs, direct Ads ownership guard and updated setup notes.
1.5.2 patched: normalized matching, privacy minimization, commerce/Blocks improvements,
identity isolation, matching transport hashing and GTM-only Google event fixes.
1.5.1 patched: API acceptance results, purchase delivery-marker repair, shared event-ID
bridge, consent-denial handling and test-only administrator diagnostic groundwork.

NOTES
Match-quality and conversion coverage depend on actual eligible visitors, real available
customer data, consent, ad blockers and destination configuration. No guaranteed score
or uplift is claimed. No test events, customer data, token values or exported database settings are
bundled. Local tests use mocked requests; no synthetic customer details are sent to Meta.

== Changelog ==

= 1.9.0 =
Event coverage (Events Manager: "Rate of pixel events covered by Conversions API", goal 75%)
* One external ID for both channels. The pixel hashed the 32-character browser ID while the server sent it raw, so
  Meta saw two different IDs for the same visitor. Browser IDs are now 64-hex values that both channels send as-is;
  existing IDs are upgraded in place (remembered matching data is kept).
* The pixel's fbq('init') now carries external_id (it was added later by a second init, after PageView had fired).
* _fbp is created in Meta's own format before Meta's script starts (the pixel adopts it), so the first server event
  of a visit has the browser ID, also when an ad blocker stops Meta's script. _fbc from ?fbclid is set in <head>,
  on the same domain Meta's pixel uses (example.ba, not www.example.ba), so there is no stale second copy.
* The server relay re-sends _fbp, _fbc and the browser ID as HTTP cookies: Safari limits cookies written by
  JavaScript to 7 days (24 hours after a click from Facebook). Click ID and browser ID survive for returning visitors.
* The browser checks the same parameters as the server before sending to Meta (landing page, campaign, traffic
  source). Before, the server could drop an event the pixel still sent: a pixel event without server coverage.
* A failed Conversions API request (network error, 429, 5xx) is retried once.
* Rate limit per visitor (IP + browser ID) instead of per IP: mobile networks put many visitors behind one IP.
* Diagnostics lists events that were not sent to the Conversions API and why (consent, sensitive page, bot,
  excluded role, rate limit ...). Warning when Facebook for WooCommerce, Meta Pixel for WordPress, Pixel Caffeine,
  Pixel Manager or Conversios is active (their pixel events can never be covered by DX's server events).

Event Match Quality (email, phone, Facebook Login ID)
* Logged-in customers: relayed events now carry their email, name, phone, address, account ID and Facebook Login ID.
  REST requests without a nonce run as logged out, so these were missing from every relayed event before.
* Login / CompleteRegistration / Comment send the whole account (was: email only), both external IDs and the login
  page as the event URL. Accounts with an excluded role are skipped.
* Profile phone and address are read from the billing fields in user meta (also without WooCommerce) and common
  phone meta keys; extend with the dxps_user_matching_raw filter.
* Returning visitors: the SHA-256 matching hashes a visitor gave in a form are kept in their own browser for the
  "Remember matching data" period and passed to fbq('init'), so pixel events carry email / phone too (before, only
  server events did). Deleted when consent is withdrawn.
* Form events: the submitted form is read before the Form / submit: event fires, so it carries that form's data.
* Location fill-in: new option "+ ZIP estimated from the city" when Cloudflare has no postal code (Meta flagged
  ZIP coverage). Main postal codes of larger cities in BA, HR, RS, SI, ME; extend with dxps_city_postcodes.

To get the full gain, switch on (Events & Parameters): Automatic detection, All forms, Remember matching data
30-90 days, Phone numbers (store country), Location fill-in "+ ZIP estimated from the city". Purge the page
cache after updating. In Events Manager: "Track events automatically without code" OFF.

= 1.8.1 =
* Facebook Login ID is now also read from Nextend Social Login (its own database table), WooCommerce Social Login
  and Super Socializer, not only from a few user-meta fields. Diagnostics shows how many accounts have one.

= 1.8.0 =
Content tracking (off until you tick content types on Events & Parameters)
* Blog posts, pages, service pages and any public custom post type send a content-view event with the post ID and
  a type (article, page, service or the post type). Titles and text are never sent to Meta.
* Service pages: by URL path (/usluge/*) or automatically for post types named service / usluge / tretman.
* Meta event: ViewContent on a site without a shop; ContentView when WooCommerce is active (keeps article views out
  of product audiences). Can be forced either way.
* Optional: archive pages (ViewCategory / ContentList) and ContentEngaged (scroll % AND visible time).
* WooCommerce is now optional. Without it DX tracks page views, content, search, leads, forms and custom events.
* Not included on purpose: AddToCart / Purchase on blog pages. Those are not real conversions.

PageScroll, TimeOnPage, InternalClick
* If these switches were never written to the database (plugin updated by upload, activation hook not run), they
  now default to ON, and missing keys are filled in once after the update. Switches saved as OFF stay OFF:
  check Diagnostics > Event switches.
* TimeOnPage counts visible time only (no events from background tabs, none lost to throttled timers).
* PageScroll also works when the theme scrolls an inner container, and is checked on load.
* InternalClick treats www and non-www as the same site and no longer counts add-to-cart links.

Matching
* Click ID (fbc): a new Meta ad click now replaces the stored click ID (browser and server). Before, the first
  click was kept for 90 days and later ad clicks were ignored.
* Location fill-in (off by default): country, or country + city + region + ZIP, from Cloudflare visitor-location
  headers on server events when the customer has not given an address. Approximate; customer data always wins.

Diagnostics
* Event switches table: what DX is set to send right now.
* Warning on every DX tab when PixelYourSite is active, and an error when it uses the same Meta pixel ID.

= 1.7.0 =
Every new feature is OFF after upgrading. Nothing changes until you switch it on (Events & Parameters / Platforms tabs).
The "Recommended setup" tab lists them. Make sure your privacy policy and consent banner cover the matching features.

Matching (Event Match Quality)
* Remember matching data: keep the hashed matching data for a returning browser for up to 180 days (default stays 10 minutes).
* All forms: detect customer data in any form, not only checkout/account forms. Search, password and payment fields stay excluded.
* URL parameters: map URL parameters (email, phone, name ...) to matching fields for email, SMS and booking links.
* Phone numbers: assume the store country when a number has no country code.
* Browser ID fallback: create the _fbp cookie in Meta's format when Meta's script is blocked.
* Always on: purchases and relayed events send both the browser ID and the account ID as external IDs.

Events
* Custom event rules: new triggers url:, scroll:, hover:, submit:, visits: and copy, next to click selectors.
  Options: value, currency, value_param, block (minutes), need_fbc, device. Existing "selector | name" lines work unchanged.
* Rules named Lead, Contact, Schedule, Subscribe, StartTrial, SubmitApplication, CompleteRegistration, FindLocation,
  CustomizeProduct or Donate are sent to Meta as standard events. NOTE: an existing rule with one of these names was a
  custom event before and becomes the standard event now.
* Form leads: successful Contact Form 7, WPForms, Elementor, Gravity, Fluent, Forminator and Ninja submissions -> Lead.
* Outbound link clicks -> OutboundClick (link domain only).

Data quality
* Block bots (browser and server relay). Excluded roles (staff and test orders are not tracked).
* Purchase value: order total, or total without tax and shipping.
* Meta Limited Data Use for the pixel and the Conversions API.

Diagnostics
* New Diagnostics tab: last 200 server events and, per event, the share that carried each matching field
  (field names only, no values). Needs "Event log" switched on.

Not included by design: lifetime-value / date-of-birth / gender enrichment (removed earlier for privacy), multiple pixels.

= 1.6.1 =
* Fix: GA4 Measurement Protocol purchases and refunds were sent with the user_id of whoever changed the order (the administrator), not the customer. Order-based sends now use the order's customer ID, and guest orders send none.
* Fix: refunds used the refunding administrator's consent cookie. They now use the customer's checkout consent snapshot, like purchases, and skip sensitive-health orders.
* Fix: "Custom events" lost every line after the first when saved. Multi-line rules are kept.
* Fix: after Save, the settings form showed the old values in that same request, and saving again would write them back. Settings now redirect after saving.
* Fix: an order status entered as "wc-completed" never matched. The wc- prefix is stripped.
* Fix: AJAX add-to-cart events had no price or value. Loop buttons now carry data-dxps-price.
* Fix: an invalid CSS selector in Custom events threw an error on every click.
* Fix: registrations or comments made by administrators in wp-admin were sent as visitor conversions.
* Security: the CAPI token and GA4 API secret are no longer printed into the admin page HTML. Leave the field blank to keep the saved value, or tick "Remove saved value".
* Robustness: if WooCommerce is inactive, tracking pauses with an admin notice instead of causing a fatal error on the front end.
* Robustness: the nightly ACR job is unscheduled when ACR is turned off or the plugin is deactivated.
* Robustness: the client IP must be a valid address; the Meta Graph API version can be overridden with the dxps_meta_api_version filter (default unchanged: v21.0).
* Text: the Server relay description no longer claims date of birth and gender are sent (they never were).

= 1.6.0 =
* New "Direct Google mode" (Platforms tab -> Direct Google mode). With GTM switched off, DX now sends the same GA4 events and parameters as the Eynna GTM container (enriched engine: view_item, view_item_list, view_category, add_to_cart, page_scroll, contact_click, click, cta_click, form_start/error, time_on_page, purchase ...) and the eh_ads_* Google Ads events directly with gtag.
* Fixes GA4 double counting in GTM mode (view_item / view_item_list / add_to_cart were sent by both DX and the container). In Direct Google mode each action is sent once.
* Purchase: the server-rendered thank-you purchase (exact order data) is used; the DOM-scraped purchase is only a 4-second fallback. Failed orders are no longer reported as purchases.
* Server-side Measurement Protocol copies of browser events are disabled in Direct Google mode (no duplicates); optional purchase/refund backup via "ga4_server_purchase".
* "GTM-only mode" is ignored while GTM is off, so direct GA4/Ads tags load.
* Google Ads eh_ads_* events are not sent on pages DX flags as health-sensitive (same rule DX already applies to Meta).
