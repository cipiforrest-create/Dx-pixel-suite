# DX Pixel Suite 2.3.0

Meta Event Match Quality (EMQ). Existing settings, feeds and order data are kept. Test on staging first: nothing in this build was run against a live WordPress site or Meta account.

EMQ is scored only from customer information on each event: email, phone, first and last name, city, state, ZIP, country, external ID, fbp, fbc, IP address and user agent. Event parameters such as page title, traffic source or event day do not affect it. This release gets more of that customer information onto more events, browser and server.

## What changes without any setup

- **The pixel receives customer data.** Meta reads customer data only from the first `fbq('init')` of a pixel and ignores a second init of the same pixel. Earlier versions started the pixel with the browser ID only and added form or server data through later inits, which Meta dropped. The first init now carries the visitor's hashed email, phone, name and address (when known) together with the browser ID.
- **Returning visitors stay matched.** A new first-party cookie, `_dxps_ud`, keeps the visitor's matching fields as SHA-256 hashes for the "Remember matching data" period. The head script passes them to the first pixel init of every page, cached pages included, and the server reads them for its copies of the events. A different email replaces the old fields, so data of two people is never mixed. The cookie is deleted when consent is withdrawn. The server-side copy is now only a 10-minute cache, so a long period does not add database rows.
- **Server events keep the customer's identity.** The relay runs through the REST API, where WordPress treats a request with an expired nonce (common on cached pages) as logged out, and WooCommerce does not load the customer. Signed-in customers are now recognised from their login cookie (read-only, for matching only), and guests' checkout billing is read from their WooCommerce session.
- **Correct visitor IP.** Behind Cloudflare, the server sees a Cloudflare address unless the host restores visitor addresses; that address was sent to Meta. DX now uses Cloudflare's visitor address when the request really comes from a Cloudflare edge server, and the forwarding headers of a local proxy or load balancer when the request comes from a private address. On a direct request those headers are ignored, because anyone could forge them. The `dxps_client_ip` filter still has the final say. Diagnostics shows which case applies and the address detected for your own request.
- **More form fields are recognised:** standard `autocomplete` values, the field's label text (including "Ime i prezime", "Broj telefona", "Grad", "Poštanski broj"), single full-name fields split into first and last name, WPForms / Gravity Forms / Elementor name fields, and email or phone inputs outside a form (popups). Passwords, payment fields, health-related fields, search boxes, company / subject fields and anything marked `data-dxps-ignore` stay excluded.
- **Login and CompleteRegistration** carry the account's names, phone and address and the account external ID, not only the email.

## Settings switched on

On update, once, values still at their old default are changed; anything set by hand stays as it is:

| Setting | New value |
|---|---|
| Remember matching data | 90 days (was 0 = 10 minutes) |
| Detect customer data in all forms | on |
| Phone default country | on (store country; site language without WooCommerce) |
| Browser ID fallback (`_fbp`) | on |
| Location fill-in | Country |

State the 90-day period in the privacy policy.

## Checks after installing

1. **Meta Events Manager → Test events** (set the test code in DX): as a new guest, fill a contact form, open another page. The PageView from the browser and from the server should both list email, phone, first and last name, external ID and fbp.
2. Signed in as a customer: ViewContent / AddToCart from the server list the billing fields and two external IDs, also after leaving the tab open for a day (expired nonce).
3. DX → Diagnostics → Visitor IP address: the detected address matches what a "what is my IP" site shows.
4. EEA test with consent refused: no `_dxps_ud` cookie and no server events.
5. Switch on the event log for a few days and watch the matching-field coverage. Meta updates EMQ over 48–72 hours.

## Regression checks

`php tests/run.php` and `node tests/browser.js` (Playwright) now also cover the matching cookie, merge order, relay identity without a nonce, IP selection (Cloudflare, local proxy, forged headers), the phone country from the site language, the single pixel init, form-to-cookie hashing and consent withdrawal.

---

# 2.3.1: fixes for the Events Manager diagnostics

## What changes

- **fbp (browser ID).** DX now writes `_fbp` in Meta's own format before the pixel starts, and `fbevents.js` adopts it. Before this, the first server copy of a new visitor's events usually left without fbp, because Meta's script had not loaded yet.
- **"Server sends fewer events than the pixel".** The relay now accepts the site's www and bare addresses, and an https site's own http pages. Before this, a visitor on the address that is not set as the site address had every server event refused. The rate limit now counts each browser separately; previously everyone behind one mobile-carrier or Cloudflare address shared a single limit. Diagnostics → Server relay lists every refused request by reason for the last 7 days.
- **"Update to IPv6".** If the site is reached over IPv4, the server can never see a visitor's IPv6 address. After consent, the browser now asks an IPv6 "what is my IP" service for its own address once a day. Server events then use that address when the connection itself is IPv4. The service is api64.ipify.org by default (the one in Meta's own example), configurable under Events & Parameters → Visitor IPv6. Add it to your privacy policy.
- **fbc (click ID).** A click ID that the landing page could not store is recovered from the previous page. This happens when consent was given on the second page, or a script redirect dropped the address parameters. Inside the Facebook and Instagram in-app browsers, Meta's official Parameter Builder is loaded (pinned version, cdn.jsdelivr.net) when no click ID is stored.

Both new options are on after the update and can be switched off under Events & Parameters.

## Reading Meta's recommendations

| Recommendation | What it means | What DX does |
|---|---|---|
| Send email / phone | Share of events that carry them | Sent for every visitor who typed them in a form, logged in or bought (2.3.0). Visitors who never identified themselves have none. That is normal for PageView, PageScroll and ViewCategory, and no plugin can send them. |
| Send Facebook Login ID | Only accounts created through Facebook login have one | Read from common login plugins (Diagnostics shows how many accounts have it). Without Facebook login on the site, ignore it. |
| Increase fbc coverage | Only visits from an ad click have a click ID | Kept for 90 days. Recovered from the previous page and in Facebook / Instagram in-app browsers (2.3.1). Organic and direct visits never have one, so 20–40% is common. |
| fbp below 100% | Server events without the browser ID | Created before the pixel starts (2.3.1). |
| Update to IPv6 / mismatched IP | The server's IP differs from what the pixel saw | Cloudflare visitor IP (2.3.0) and browser IPv6 lookup (2.3.1). |
| Server sends fewer events than the pixel | Some server copies were refused or not sent | Address and rate-limit fixes (2.3.1). The causes that remain are listed under Diagnostics → Server relay. |

Meta's scores look back over 7 to 28 days, so the effect shows gradually over 2 to 7 days after updating.
