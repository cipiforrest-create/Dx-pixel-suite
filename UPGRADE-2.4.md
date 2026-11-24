# DX Pixel Suite 2.4.0

More customer information on more events. Existing settings are kept. Test on staging first: nothing here was run against a live WordPress site or Meta account.

## Reading the Events Manager numbers

Meta's ViewContent screen showed email, phone, name, city and ZIP each at **0.66%**. These numbers mean the following:

- **0.66% means that few visitors have ever identified themselves.** DX sends email and phone for every visitor who typed them, logged in or bought, and remembers them for 90 days. It cannot invent them for people who never gave them. That is why the levers below are about getting more visitors to identify themselves.
- **Country was at 100% because WooCommerce assigns its default customer location (the store's country) to every guest.** From 2.4.0, an anonymous visitor's country comes from Cloudflare instead.
- **fbc (click ID) is only present on visits from an ad click.** A low percentage means few of the visits come straight from ads.
- **Gender and date of birth are deliberately never sent.** DX does not collect or guess them.

## What changes without setup

- **Location fill-in works.** City, region, ZIP and country come from Cloudflare and are added to server events when the customer has given none. The customer's own data always wins. DX believes these headers only when the request really comes from a Cloudflare server, so they can't be forged on direct requests. The values are approximate (taken from the IP address), so expect higher coverage numbers but only a modest real matching gain.
  - **Required once:** in Cloudflare, open **Rules → Settings (Managed Transforms)** and switch on **Add visitor location headers**. DX → Diagnostics → Visitor IP address shows whether the headers arrive.
- **Order-email links.** In WooCommerce emails to the customer about an order (processing, completed, on hold, invoice, customer note), links to your shop get a signed token: `?dxr=…`.
  - The token contains no email, name or other personal data, and only your site can create a valid one.
  - When the buyer opens such a link and has given consent on the site, DX stores the order's hashed email, phone, name and address in that browser. Their next events match them, even on a new phone or computer.
  - The token is removed from the address bar right away, and it expires after 90 days.
  - Only orders whose buyer allowed marketing at checkout get tokens.
  - Add a sentence about it to your privacy policy.
  - Switch it off under Events & Parameters → Order-email links.

## Getting more visitors to identify themselves

This is the lever that moves email and phone coverage. DX reads all of these automatically.

1. **Facebook Login.** This is Meta's top suggestion, "Send Facebook Login ID". Install **Nextend Social Login** (free) and enable "Continue with Facebook" on My Account and checkout. DX already reads the Facebook Login ID from Nextend and other login plugins, and sends it for that customer's events. Diagnostics shows how many accounts have one.
2. **A newsletter / discount sign-up.** Use a small first-order discount for email or phone, built with a form plugin DX recognises: Contact Form 7, WPForms, Elementor Forms, Fluent Forms, Gravity Forms or Forminator. Every visitor who signs up is matched for 90 days on every event.
3. **Account creation at checkout.** In WooCommerce → Settings → Accounts, allow customers to create an account during checkout. Logged-in customers are matched on every event, on every device.
4. **"Notify me when back in stock" and consultation / booking forms.** Each one is another chance for a visitor to leave an email or phone number.
5. **WhatsApp.** A WhatsApp click is tracked as a Lead, but it carries no customer data. Meta cannot match it to a person. Where possible, use a short form (name + phone) before or next to the WhatsApp button.

## Checks after installing

1. DX → Diagnostics → Visitor IP address: "Location headers: arriving".
2. Place a test order with marketing consent, open the order email and click a link. The next PageView in Meta Test Events should show email, phone, first and last name.
3. Over 2–7 days in Events Manager: city, region and ZIP coverage should rise on all events, and email/phone should rise as more buyers return through their emails.

---

# 2.4.1

## Location fill-in on hosts that restore the visitor IP

Many hosts behind Cloudflare replace the connection address with the visitor's real address before WordPress runs: LiteSpeed, mod_remoteip, and cPanel's Cloudflare integration. 2.4.0 then saw a "direct" request and ignored Cloudflare's location headers. As a result, server events carried no city, region or ZIP, and the country was WooCommerce's default store country.

2.4.1 trusts the headers in that situation too, when all of the following hold:
- Cloudflare's `CF-Ray` header is present;
- Cloudflare's visitor address (`CF-Connecting-IP`) equals the connection address.

Someone who forges those headers on a direct request can only change the location on their own events.

Without Cloudflare, DX uses WooCommerce's MaxMind database when it is set up: WooCommerce → Settings → Integration → MaxMind Geolocation, with a free licence key from maxmind.com. The lookup is local and cached per IP for a day.

**DX → Diagnostics → Visitor IP address** now shows:
- **Location fill-in source:** Cloudflare, Cloudflare (restored by host), MaxMind or none, plus the next step if something is missing.
- **Cloudflare headers on your request**, by name.
- **What DX sends for you right now:** the matching field names for your own request.

The event log (Events & Parameters → Event log) shows the fields of every server event. After this update, anonymous PageView / ViewContent events should list `ct, st, zp` next to `country, fbp, client_ip_address, client_user_agent, external_id`.

## Meta shows parameters as `_removed_` (restricted category)

Under Sampled activities, Meta says: "Your parameters and referring URL are displayed as _removed_". That means Meta has put the domain in a restricted category. A shop selling wigs and hair-loss products is often classed as health-related. DX cannot change this, and it is not caused by DX.

**What it limits:**
- custom event parameters and full URLs, which Meta discards;
- custom audiences and custom conversions built on URLs or parameters;
- some optimisation options.

**What it does not limit:**
- matching: email, phone, fbp and the other EMQ fields still count;
- standard events such as Purchase, AddToCart and ViewContent;
- their value and currency.

**To check and request a review:**
1. Events Manager → your dataset → **Settings**: look for the notice about restricted / sensitive data on the domain.
2. If the business does not offer health or medical services, request a review there, or through Meta Business Help Center → Business support → "Events Manager / data restrictions". Describe the business as a beauty / hair-fashion retailer.
3. Meta decides the outcome, and the review can take several days. Product names, page titles and URLs that mention medical conditions make the classification more likely. DX already keeps such words out of everything it sends.

## Why EMQ differs by event

- **Login, CompleteRegistration, Purchase:** the visitor is known, so DX sends email, phone, name, address and IDs. EMQ is around 8.
- **PageView, ViewContent, PageScroll:** most visitors are anonymous. DX sends IP, browser, fbp, external ID, location and fbc where available, which caps these events at around 5–6.
- **Email and phone appear on these events only for visitors who identified themselves at some point.** That happens through a form, login, a purchase, or an order-email link. Meta's overview shows the browser side of this: "24% of your PageView events are receiving hashed customer information". The levers in "Getting more visitors to identify themselves" above move this number.
