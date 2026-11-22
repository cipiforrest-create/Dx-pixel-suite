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
