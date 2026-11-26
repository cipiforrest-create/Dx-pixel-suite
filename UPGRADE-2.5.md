# DX Pixel Suite 2.5.0

Targets the Events Manager warnings for **event coverage** (ViewContent at 59%, goal 75%) and **ZIP coverage**. Existing settings are kept. Test on staging first: nothing here was run against a live WordPress site or Meta account.

## What changes without setup

- **One _fbp / _fbc cookie.** Meta's pixel writes these cookies on the parent domain (`example.ba`), but DX wrote them host-only (`www.example.ba`). The browser then had two cookies with the same name, and after a new ad click the stale one could be read. DX now writes them where the pixel does, from the page head, its script and the server.
- **Safari keeps the IDs.** Safari deletes cookies written by JavaScript after 7 days (24 hours after a click from Facebook). Each relayed event now re-sends `_fbp`, `_fbc` and the browser ID as server cookies with the same values.
- **No more pixel-only events the server would drop.** The server refused events whose landing page, campaign or traffic source looked health-related. The browser only checked the event itself, so the pixel still sent them, and Events Manager counted a pixel event with no server copy. Both sides now check the same data. Google tracking is unchanged.
- **One retry** for relayed events when the request to Meta fails (network error, rate limit, Meta server error).
- **Warning in the admin** when another Meta pixel plugin is active (Facebook for WooCommerce, Meta Pixel for WordPress, Pixel Caffeine, Pixel Manager, Conversios). Their events have their own event IDs, so DX's server events can never cover them.

## Optional

- **Events & Parameters → Location fill-in → "+ ZIP estimated from the city".** Use this when Cloudflare (or MaxMind) knows the visitor's city but not the postal code. DX then sends the city's main postal code (larger cities in BA, HR, RS, SI, ME). Developers can add cities with the `dxps_city_postcodes` filter. The ZIP is approximate, like the rest of location fill-in, and the customer's own address always wins.

## Outside the plugin

- **Events Manager → dataset → Settings:** switch "Track events automatically without code" **off**. Events Meta detects on its own have no DX event ID and lower coverage. Keep "Automatic advanced matching" **on**.
- **Meta tags in GTM, or another plugin** sending to the same pixel lower coverage in the same way.
- **Cloudflare:** Bot Fight Mode or WAF rules must not challenge `/wp-json/dxps/v1/`. A challenged relay request is a lost server event.
- Purge the page cache after updating.
