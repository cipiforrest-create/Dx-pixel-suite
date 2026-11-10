<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class DXPS_Settings {

	/** Credentials: never echoed back into the admin HTML; a blank submission keeps the stored value. */
	const SECRETS = array( 'meta_capi_token', 'ga4_api_secret' );

	/** field => tab registry: text fields and checkbox fields per tab (prevents cross-tab wipes on save). */
	private static $tabs = array(
		'platforms' => array(
			'text' => array( 'meta_pixel_id', 'meta_capi_token', 'meta_test_code', 'ga4_id', 'ga4_api_secret', 'gads_conversion_id', 'gads_purchase_label', 'gads_lead_label', 'gtm_id' ),
			'bool' => array( 'meta_enabled', 'ga4_enabled', 'gads_enabled', 'gads_enhanced', 'gtm_enabled', 'gtm_only', 'engine_direct', 'ga4_server_purchase', 'meta_ldu' ),
		),
		'events' => array(
			'text' => array( 'purchase_status', 'time_on_page', 'excluded_coupons', 'product_id_format', 'brand_name', 'custom_events', 'am_retention_days', 'url_identity', 'exclude_roles', 'purchase_value' ),
			'bool' => array( 'server_relay', 'track_leads', 'track_search', 'track_scroll', 'track_time', 'track_clicks', 'track_forms', 'track_downloads', 'track_signup', 'track_login', 'track_comments', 'track_brand', 'acr_enabled', 'auto_am', 'debug_log', 'am_all_forms', 'phone_default_country', 'fbp_fallback', 'track_form_leads', 'track_outbound', 'block_bots', 'event_log' ),
		),
		'consent' => array(
			'text' => array(),
			'bool' => array( 'consent_mode' ),
		),
	);

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'save' ) );
	}

	public static function menu() {
		add_menu_page( 'DX Pixel Suite', 'DX Pixel Suite', 'manage_options', 'dx-pixel-suite', array( __CLASS__, 'page' ), 'dashicons-chart-line', 58 );
	}

	public static function save() {
		if ( isset( $_POST['dxps_clear_log'] ) && current_user_can( 'manage_options' ) ) {
			check_admin_referer( 'dxps_settings' );
			delete_option( 'dxps_event_log' );
			wp_safe_redirect( admin_url( 'admin.php?page=dx-pixel-suite&tab=diagnostics' ) );
			exit;
		}
		if ( ! isset( $_POST['dxps_save'], $_POST['dxps_tab'] ) || ! current_user_can( 'manage_options' ) ) return;
		check_admin_referer( 'dxps_settings' );
		$tab = sanitize_key( $_POST['dxps_tab'] );
		if ( ! isset( self::$tabs[ $tab ] ) ) return;

		$o = get_option( 'dxps_settings', array() );
		foreach ( self::$tabs[ $tab ]['text'] as $k ) {
			$v = isset( $_POST[ $k ] ) ? wp_unslash( $_POST[ $k ] ) : '';
			if ( in_array( $k, self::SECRETS, true ) ) {
				// Blank = keep the stored credential (it is not rendered back into the form); explicit clear wipes it.
				$v = trim( sanitize_text_field( $v ) );
				if ( '' !== $v )                           $o[ $k ] = $v;
				elseif ( isset( $_POST[ $k . '_clear' ] ) ) $o[ $k ] = '';
				continue;
			}
			// custom_events is one rule per line; sanitize_text_field() would collapse all lines into one.
			$o[ $k ] = in_array( $k, array( 'custom_events', 'url_identity' ), true ) ? sanitize_textarea_field( $v ) : sanitize_text_field( $v );
		}
		foreach ( self::$tabs[ $tab ]['bool'] as $k ) $o[ $k ] = isset( $_POST[ $k ] ) ? 1 : 0;
		if ( 'events' === $tab ) {
			$o['purchase_trigger'] = ( isset( $_POST['purchase_trigger'] ) && 'thankyou' === $_POST['purchase_trigger'] ) ? 'thankyou' : 'status';
			$o['time_on_page']     = max( 5, min( 600, absint( $o['time_on_page'] ?: 30 ) ) );
			// WooCommerce passes status slugs without the "wc-" prefix; "wc-completed" would never match.
			$status = preg_replace( '/^wc-/', '', sanitize_key( $o['purchase_status'] ?? '' ) );
			$o['purchase_status'] = '' !== $status ? $status : 'completed';
			$o['am_retention_days'] = max( 0, min( 180, absint( $o['am_retention_days'] ?? 0 ) ) );
			$o['purchase_value']    = 'net' === ( $o['purchase_value'] ?? '' ) ? 'net' : 'total';
			$o['exclude_roles']     = implode( ',', array_filter( array_map( 'sanitize_key', explode( ',', (string) ( $o['exclude_roles'] ?? '' ) ) ) ) );
			if ( empty( $o['event_log'] ) ) delete_option( 'dxps_event_log' );
		}
		update_option( 'dxps_settings', $o );
		// Post/Redirect/Get: DXPS_Helpers::opt() caches the options for the request, so rendering the form in
		// this same request would show (and on the next save, re-submit) the pre-save values.
		wp_safe_redirect( admin_url( 'admin.php?page=dx-pixel-suite&tab=' . $tab . '&dxps_saved=1' ) );
		exit;
	}

	private static function f( $key, $ph = '' ) {
		if ( in_array( $key, self::SECRETS, true ) ) {
			$saved = '' !== (string) DXPS_Helpers::opt( $key );
			echo '<input type="password" autocomplete="new-password" class="regular-text" name="' . esc_attr( $key ) . '" value="" placeholder="' . esc_attr( $saved ? 'Saved — leave blank to keep' : $ph ) . '">';
			if ( $saved ) echo ' <label><input type="checkbox" name="' . esc_attr( $key ) . '_clear" value="1"> Remove saved value</label>';
			return;
		}
		echo '<input type="text" autocomplete="off" class="regular-text" name="' . esc_attr( $key ) . '" value="' . esc_attr( DXPS_Helpers::opt( $key ) ) . '" placeholder="' . esc_attr( $ph ) . '">';
	}

	private static function c( $key, $label, $desc = '' ) {
		echo '<label><input type="checkbox" name="' . esc_attr( $key ) . '" value="1" ' . checked( DXPS_Helpers::on( $key ), true, false ) . '> ' . esc_html( $label ) . '</label>';
		if ( $desc ) echo '<p class="description">' . wp_kses_post( $desc ) . '</p>';
	}

	public static function page() {
		$tab = sanitize_key( $_GET['tab'] ?? 'platforms' );
		echo '<div class="wrap"><h1>DX Pixel Suite <small style="font-weight:normal">v' . esc_html( DXPS_VERSION ) . '</small></h1><nav class="nav-tab-wrapper">';
		foreach ( array( 'platforms' => 'Platforms', 'events' => 'Events & Parameters', 'consent' => 'Consent & Privacy', 'diagnostics' => 'Diagnostics', 'recommended' => '✓ Recommended setup' ) as $k => $l ) {
			echo '<a class="nav-tab ' . ( $tab === $k ? 'nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=dx-pixel-suite&tab=' . $k ) ) . '">' . esc_html( $l ) . '</a>';
		}
		echo '</nav>';
		if ( ! empty( $_GET['dxps_saved'] ) ) echo '<div class="notice notice-success is-dismissible"><p>DX Pixel Suite settings saved.</p></div>';
		if ( 'recommended' === $tab ) { self::tab_recommended(); echo '</div>'; return; }
		if ( 'diagnostics' === $tab ) { self::tab_diagnostics(); echo '</div>'; return; }

		echo '<form method="post"><input type="hidden" name="dxps_tab" value="' . esc_attr( $tab ) . '">';
		wp_nonce_field( 'dxps_settings' );
		if ( 'platforms' === $tab ) self::tab_platforms();
		if ( 'events' === $tab )    self::tab_events();
		if ( 'consent' === $tab )   self::tab_consent();
		echo '<p class="submit"><button type="submit" name="dxps_save" value="1" class="button button-primary">Save settings</button></p></form></div>';
	}

	/* ================= Platforms ================= */

	private static function tab_platforms() {
		echo '<h2>Meta — Facebook &amp; Instagram</h2><table class="form-table">';
		echo '<tr><th></th><td>'; self::c( 'meta_enabled', 'Enable Meta Pixel + Conversions API' ); echo '</td></tr>';
		echo '<tr><th>Pixel ID</th><td>'; self::f( 'meta_pixel_id', '938533020109061' ); echo '</td></tr>';
		echo '<tr><th>Conversions API token</th><td>'; self::f( 'meta_capi_token' ); echo '<p class="description">Events Manager → Settings → Conversions API → Generate access token.</p></td></tr>';
		echo '<tr><th>Test event code</th><td>'; self::f( 'meta_test_code', 'TEST12345' ); echo '<p class="description">Only while testing — remove for production. The diagnostic button sends one test PageView without saving this code or enabling live Meta tracking.</p><button type="button" class="button" id="dxps-capi-test">Send test PageView</button><p id="dxps-capi-test-status" role="status" aria-live="polite"></p></td></tr>';
		$ajax_url = wp_json_encode( admin_url( 'admin-ajax.php' ) );
		echo "<script>(function(){var b=document.getElementById('dxps-capi-test'),out=document.getElementById('dxps-capi-test-status');b.addEventListener('click',function(){var code=document.querySelector('input[name=meta_test_code]').value.trim(),nonce=document.querySelector('input[name=_wpnonce]').value;if(!/^TEST[0-9]{1,30}$/.test(code)){out.textContent='Enter a valid Meta TEST code first.';return;}b.disabled=true;out.textContent='Sending a test-only PageView...';fetch({$ajax_url},{method:'POST',credentials:'same-origin',body:new URLSearchParams({action:'dxps_test_capi',nonce:nonce,test_event_code:code})}).then(function(r){return r.json()}).then(function(j){var d=j.data||{};out.textContent=d.ok?'Accepted by Meta: '+d.events_received+' test event(s). Event ID: '+d.event_id:'Test failed: '+(d.status||'unknown')+(d.http_status?' (HTTP '+d.http_status+')':'')+(d.error_code?' (Meta code '+d.error_code+')':'');}).catch(function(){out.textContent='Test request failed. No settings were changed.';}).finally(function(){b.disabled=false;});});})();</script>";
		echo '<tr><th>Limited Data Use</th><td>'; self::c( 'meta_ldu', 'Send Meta events with Limited Data Use (LDU)', 'For US-state privacy laws. Meta geolocates each event and restricts how it uses the data where those laws apply. Applies to the browser pixel and the Conversions API. Leave off if you do not sell to the US.' ); echo '</td></tr>';
		echo '</table><h2>Google Analytics 4</h2><table class="form-table">';
		echo '<tr><th></th><td>'; self::c( 'ga4_enabled', 'Enable GA4 (browser tag + server Measurement Protocol)' ); echo '</td></tr>';
		echo '<tr><th>Measurement ID</th><td>'; self::f( 'ga4_id', 'G-XXXXXXXXXX' ); echo '</td></tr>';
		echo '<tr><th>MP API secret</th><td>'; self::f( 'ga4_api_secret' ); echo '<p class="description">GA4 Admin → Data streams → stream → Measurement Protocol API secrets.</p></td></tr>';
		echo '</table><h2>Google Ads</h2><table class="form-table">';
		echo '<tr><th></th><td>'; self::c( 'gads_enabled', 'Enable Google Ads conversion tracking' ); echo '</td></tr>';
		echo '<tr><th>Conversion ID</th><td>'; self::f( 'gads_conversion_id', 'AW-XXXXXXXXX' ); echo '</td></tr>';
		echo '<tr><th>Purchase label</th><td>'; self::f( 'gads_purchase_label' ); echo '</td></tr>';
		echo '<tr><th>Lead label</th><td>'; self::f( 'gads_lead_label' ); echo '<p class="description">Optional — WhatsApp/phone/email clicks as a secondary conversion.</p></td></tr>';
		echo '<tr><th></th><td>'; self::c( 'gads_enhanced', 'Enhanced Conversions (hashed billing data with purchases)' ); echo '</td></tr>';
		echo '</table><h2>Google Tag Manager</h2><table class="form-table">';
		echo '<tr><th></th><td>'; self::c( 'gtm_enabled', 'Enable GTM container' ); echo '</td></tr>';
		echo '<tr><th>Container ID</th><td>'; self::f( 'gtm_id', 'GTM-XXXXXXX' ); echo '</td></tr>';
		echo '<tr><th></th><td>'; self::c( 'gtm_only', 'GTM-only mode', 'Push dataLayer events only; do not load direct GA4/Ads tags (avoids double-tracking if your container fires them).' ); echo '</td></tr>';
		echo '</table><h2>Direct Google mode (replaces GTM)</h2><table class="form-table">';
		echo '<tr><th></th><td>'; self::c( 'engine_direct', 'Run the enriched event engine inside DX (no GTM needed)', 'Sends the same GA4 events and parameters as the GTM container (view_item, add_to_cart, page_scroll, contact_click, purchase …) plus the eh_ads_* Google Ads events straight from DX. Works only while "Enable GTM container" above is OFF; needs GA4 (and optionally Google Ads) enabled with IDs filled in.' ); echo '</td></tr>';
		echo '<tr><th></th><td>'; self::c( 'ga4_server_purchase', 'Also send purchase / refund through GA4 Measurement Protocol', 'Optional server-side backup in Direct Google mode (GA4 de-duplicates by transaction ID). Other events are never mirrored to GA4 server-side in this mode, to avoid double counting.' ); echo '</td></tr>';
		if ( DXPS_Helpers::on( 'engine_direct' ) ) {
			echo '<tr><th>Status</th><td><strong>' . ( DXPS_Helpers::engine_direct() ? 'Active' : 'Not active' ) . '</strong>' . ( DXPS_Helpers::engine_direct() ? '' : ' - turn GTM off and enable GA4 and/or Google Ads with their IDs.' ) . '</td></tr>';
		}
		echo '</table>';
	}

	/* ================= Events & Parameters ================= */

	private static function tab_events() {
		$o = get_option( 'dxps_settings', array() );
		echo '<h2>Purchase</h2><table class="form-table">';
		echo '<tr><th>Purchase trigger</th><td>
			<label><input type="radio" name="purchase_trigger" value="status" ' . checked( ( $o['purchase_trigger'] ?? 'status' ), 'status', false ) . '> On order status (server-side, COD-safe — recommended for pouzeće)</label><br>
			<label><input type="radio" name="purchase_trigger" value="thankyou" ' . checked( ( $o['purchase_trigger'] ?? '' ), 'thankyou', false ) . '> On thank-you page (browser, immediate)</label>
			<p class="description">Google Ads conversion always fires on the thank-you page regardless.</p></td></tr>';
		echo '<tr><th>Order status</th><td>'; self::f( 'purchase_status', 'completed' ); echo '<p class="description">Slug without wc- prefix.</p></td></tr>';
		echo '<tr><th>Purchase value</th><td><select name="purchase_value">';
		foreach ( array( 'total' => 'Order total (default)', 'net' => 'Order total without tax and shipping' ) as $vk => $vl ) {
			echo '<option value="' . esc_attr( $vk ) . '" ' . selected( DXPS_Helpers::opt( 'purchase_value', 'total' ), $vk, false ) . '>' . esc_html( $vl ) . '</option>';
		}
		echo '</select><p class="description">The value sent with Purchase to every platform. Choose the net option to optimise ads on what you actually receive.</p></td></tr>';
		echo '</table><h2>Events</h2><table class="form-table">';
		echo '<tr><th>Server relay</th><td>'; self::c( 'server_relay', 'Mirror browser events through Meta CAPI + GA4 MP', 'First-party, deduplicated by event_id — recovers ad-blocker/iOS losses. Relayed events carry the validated, hashed customer data that is actually available (email, phone, name, city, state, ZIP, country, external ID, Facebook Login ID). Date of birth and gender are never inferred or sent.' ); echo '</td></tr>';
		echo '<tr><th>Leads</th><td>'; self::c( 'track_leads', 'WhatsApp / phone / email clicks → Lead' ); echo '</td></tr>';
		echo '<tr><th>Search</th><td>'; self::c( 'track_search', 'Site search → Search' ); echo '</td></tr>';
		echo '<tr><th>PageScroll</th><td>'; self::c( 'track_scroll', 'Scroll depth at 25 / 50 / 75 / 90% → PageScroll (Meta) + scroll (GA4)', 'Great for engaged-visitor custom audiences.' ); echo '</td></tr>';
		echo '<tr><th>TimeOnPage</th><td>'; self::c( 'track_time', 'Fire TimeOnPage after' ); echo ' <input type="number" min="5" max="600" name="time_on_page" value="' . esc_attr( DXPS_Helpers::opt( 'time_on_page', 30 ) ) . '" style="width:70px"> seconds</td></tr>';
		echo '<tr><th>Internal clicks</th><td>'; self::c( 'track_clicks', 'Clicks on internal links → InternalClick (with link_url + link_text)', 'Capped at 15 per pageview to keep event volume sane.' ); echo '</td></tr>';
		echo '<tr><th>Forms</th><td>'; self::c( 'track_forms', 'Form submissions → Form (form_id + form_action as parameters)' ); echo '</td></tr>';
		echo '<tr><th>Downloads</th><td>'; self::c( 'track_downloads', 'File download clicks → Download (file name + type as parameters)' ); echo '</td></tr>';
		echo '<tr><th>Form leads</th><td>'; self::c( 'track_form_leads', 'Successful form submissions → Lead', 'Fires when Contact Form 7, WPForms, Elementor Forms, Gravity Forms, Fluent Forms, Forminator or Ninja Forms confirms a submission. Sent to Meta as the standard Lead event, with the matching data detected in the form.' ); echo '</td></tr>';
		echo '<tr><th>Outbound clicks</th><td>'; self::c( 'track_outbound', 'Clicks on links to other websites → OutboundClick (link domain only)', 'Capped at 10 per pageview.' ); echo '</td></tr>';
		echo '<tr><th>Sign-ups</th><td>'; self::c( 'track_signup', 'User registrations → CompleteRegistration (server-side)' ); echo '</td></tr>';
		echo '<tr><th>Logins</th><td>'; self::c( 'track_login', 'User logins → Login (server-side)' ); echo '</td></tr>';
		echo '<tr><th>Comments</th><td>'; self::c( 'track_comments', 'Comments → Comment (server-side)' ); echo '</td></tr>';
		echo '<tr><th>ACR</th><td>'; self::c( 'acr_enabled', 'Automatic Conversion Recovery — nightly sweep re-sends purchases the pixel/status hook missed (last 7 days, deduped by order)' ); echo '</td></tr>';
		echo '</table><h2>Customer data (Advanced Matching)</h2><table class="form-table">';
		echo '<tr><th>Automatic detection</th><td>';
		self::c( 'auto_am', 'Scan page forms for customer data and send it with every event', 'Detects valid email, phone, first/last name, city, postcode, state and country in checkout/account forms or forms explicitly opted in with data-dxps-match — including browser autofill and multi-step checkout. Values feed Meta Advanced Matching in the browser (the pixel hashes them locally) and, via the server relay, hashed customer data for the Conversions API. This is the single biggest lever on Event Match Quality: it raises the "percent of events sending" figures Meta shows for phone, first name, last name and city.' );
		echo '<p class="description" style="margin-top:6px"><strong>Never captured:</strong> passwords, payment card numbers, CVV/CVC, IBAN. Add <code>data-dxps-ignore</code> to any other field you want excluded. Matching fields are validated, normalized and SHA-256 hashed before the first-party form-ingest request. Only matching hashes are retained briefly. Browser Advanced Matching uses Meta’s own hashing. No customer information is embedded in cacheable page HTML. Requires the server relay for the CAPI half. Make sure your privacy policy and cookie banner cover this before enabling it in the EEA.</p>';
		echo '</td></tr>';
		echo '<tr><th>All forms</th><td>'; self::c( 'am_all_forms', 'Detect customer data in every form, not only checkout and account forms', 'Covers contact, newsletter and booking forms without adding <code>data-dxps-match</code>. Search boxes, passwords, payment fields and anything marked <code>data-dxps-ignore</code> stay excluded.' ); echo '</td></tr>';
		echo '<tr><th>Remember matching data</th><td><input type="number" min="0" max="180" name="am_retention_days" value="' . esc_attr( (int) DXPS_Helpers::opt( 'am_retention_days', 0 ) ) . '" style="width:70px"> days <p class="description">How long the hashed matching data is kept for a returning browser, so later events (page views, add to cart) still carry it. <strong>0 = 10 minutes</strong> (default). 90 is typical. Only SHA-256 hashes are stored, they are deleted when consent is withdrawn, and your privacy policy should state the period.</p></td></tr>';
		echo '<tr><th>URL parameters</th><td><textarea name="url_identity" class="large-text" rows="3" placeholder="email | em' . "\n" . 'phone | ph' . "\n" . 'name | name">' . esc_textarea( DXPS_Helpers::opt( 'url_identity' ) ) . '</textarea><p class="description">One per line: <code>url_parameter | field</code>. For email, SMS or booking links that arrive with customer details in the URL (for example <code>?email=…&amp;phone=…</code>). Fields: <code>em</code> <code>ph</code> <code>fn</code> <code>ln</code> <code>name</code> (full name) <code>ct</code> <code>st</code> <code>zp</code> <code>country</code>. Values are hashed in the browser and never sent as part of a page URL.</p></td></tr>';
		echo '<tr><th>Phone numbers</th><td>'; self::c( 'phone_default_country', 'Assume the store country for phone numbers written without a country code', 'Meta only matches phone numbers that include a country code. Without this, local-format numbers from forms that have no country field are dropped.' ); echo '</td></tr>';
		echo '<tr><th>Browser ID fallback</th><td>'; self::c( 'fbp_fallback', 'Create the _fbp browser ID cookie when Meta&#8217;s script is blocked', 'Ad blockers stop Meta&#8217;s script, so it never sets <code>_fbp</code> and server events lose that signal. DX then creates the cookie in Meta&#8217;s own format, after consent.' ); echo '</td></tr>';
		echo '</table><h2>Data quality</h2><table class="form-table">';
		echo '<tr><th>Bots</th><td>'; self::c( 'block_bots', 'Do not track crawlers, uptime monitors and headless browsers', 'Checked in the browser and again on the server relay.' ); echo '</td></tr>';
		echo '<tr><th>Excluded roles</th><td>'; self::f( 'exclude_roles', 'administrator, shop_manager' ); echo '<p class="description">Comma-separated role slugs. Logged-in users with these roles are not tracked, and their test orders are not sent as purchases.</p></td></tr>';
		echo '</table><h2>Product data</h2><table class="form-table">';
		echo '<tr><th>Product ID format</th><td><select name="product_id_format">';
		foreach ( array( 'id' => 'Post ID (default)', 'sku' => 'SKU', 'fb_woo' => 'Facebook for WooCommerce (wc_post_id_X) — match existing FB catalogs' ) as $fk => $fl ) {
			echo '<option value="' . esc_attr( $fk ) . '" ' . selected( DXPS_Helpers::opt( 'product_id_format', 'id' ), $fk, false ) . '>' . esc_html( $fl ) . '</option>';
		}
		echo '</select><p class="description">Used in content_ids / items on all platforms. Match your Merchant Center / FB catalog ID scheme (DX Feed Manager uses dx-{ID}; pick Post ID and set the same in dynamic ads).</p></td></tr>';
		echo '<tr><th>Brand</th><td>'; self::c( 'track_brand', 'Send brand parameter with every product event' ); echo ' <input name="brand_name" value="' . esc_attr( DXPS_Helpers::opt( 'brand_name', 'Eynna Hair' ) ) . '"></td></tr>';
		echo '</table><h2>Custom events</h2><table class="form-table">';
		echo '<tr><th>Event rules</th><td><textarea name="custom_events" class="large-text code" rows="6" placeholder=".btn-custom-wig | custom_wig_click' . "\n" . 'url:/hvala-na-upitu* | Lead | block=30' . "\n" . 'url:/termin-potvrden | Schedule | value_param=amount, currency=BAM' . "\n" . 'scroll:60 | deep_reader | device=mobile">' . esc_textarea( DXPS_Helpers::opt( 'custom_events' ) ) . '</textarea>';
		echo '<p class="description">One per line: <code>trigger | event_name | options</code> (options are optional). Fires to Meta, GA4, the GTM dataLayer and the server relay, with customer data attached.</p>';
		echo '<p class="description"><strong>Triggers:</strong> a CSS selector (click) · <code>url:/path</code> or <code>url:/path*</code> (page load; * matches everything after) · <code>scroll:50</code> (percent) · <code>hover:selector</code> · <code>submit:form-selector</code> · <code>visits:3</code> (the visitor&#8217;s 3rd page view) · <code>copy</code> (text copied).</p>';
		echo '<p class="description"><strong>Options:</strong> <code>value=79</code> · <code>currency=EUR</code> · <code>value_param=amount</code> (read the value from a URL parameter) · <code>block=30</code> (not again from the same browser within 30 minutes, so a refreshed thank-you page does not count twice) · <code>need_fbc=1</code> (only visitors who came from a Meta ad) · <code>device=mobile</code> or <code>desktop</code>.</p>';
		echo '<p class="description"><strong>Event names:</strong> Lead, Contact, Schedule, Subscribe, StartTrial, SubmitApplication, CompleteRegistration, FindLocation, CustomizeProduct and Donate are sent to Meta as standard events. Any other name is sent as a custom event.</p></td></tr>';
		echo '</table><h2>Parameters</h2>';
		echo '<p>All events automatically carry the full parameter set — no toggles needed, everything the PRO plugins gate is on by default:</p>';
		echo '<p><code>page_title</code> <code>post_type</code> <code>post_id</code> <code>post_category</code> <code>content_name</code> <code>event_url</code> <code>landing_page</code> <code>user_role</code> <code>plugin</code> <code>event_time</code> <code>event_day</code> <code>event_month</code> <code>traffic_source</code> <code>utm_source/medium/campaign/term/content</code></p>';
		echo '<p>WooCommerce events add: <code>category_name</code> <code>tags</code> <code>num_items</code> <code>content_ids</code> <code>content_type</code> <code>value</code> — and purchases add <code>total</code> <code>subtotal</code> <code>tax</code> <code>shipping</code> <code>shipping_cost</code> <code>fees</code> <code>coupon_used</code> <code>coupon_name</code> <code>transactions_count</code> <code>average_order</code> <code>predicted_ltv</code> <code>payment_method</code> <code>new_customer</code>.</p>';
		echo '<table class="form-table">';
		echo '<tr><th>Excluded coupons</th><td>'; self::f( 'excluded_coupons', 'alopeci' ); echo '<p class="description">Comma-separated keywords — matching coupons are <strong>never</strong> sent to any platform (health-condition signals are prohibited by Meta/Google policy). Keep <code>alopeci</code>.</p></td></tr>';
		echo '<tr><th>Event log</th><td>'; self::c( 'event_log', 'Record the last 200 server events for the Diagnostics tab', 'Stores the event name, the result and which matching fields were present. No values, hashes or IP addresses. Adds one database write per server event, so switch it on while checking your setup and off afterwards.' ); echo '</td></tr>';
		echo '<tr><th>Debug log</th><td>'; self::c( 'debug_log', 'Log server events (WooCommerce → Status → Logs → dx-pixel-suite)' ); echo '</td></tr>';
		echo '</table>';
	}

	/* ================= Consent ================= */

	private static function tab_consent() {
		echo '<h2>Consent Mode v2</h2><table class="form-table"><tr><th></th><td>';
		self::c( 'consent_mode', 'Enable Consent Mode v2',
			'<strong>ON:</strong> Google tags default to <em>denied</em> for EEA/UK/CH visitors (required for your HR/SI/DE traffic) and <em>granted</em> elsewhere — BA/RS visitors are unaffected. Meta Pixel is held for EEA visitors until your banner calls <code>dxpsConsent(\'granted\')</code>. Server relay respects the same consent state.<br><strong>OFF:</strong> everything fires immediately for everyone.' );
		echo '</td></tr></table>';
		echo '<h2>Privacy hard rules (always active)</h2><ul style="list-style:disc;margin-left:20px">
			<li>All customer data is SHA-256 hashed before transmission (Meta/Google spec).</li>
			<li>Coupons matching the excluded keywords never reach any platform.</li>
			<li>DX suppresses recognized sensitive-health contexts and excludes free-text fields; review custom integrations and destinations separately.</li></ul>';
	}

	/* ================= Diagnostics ================= */

	private static function tab_diagnostics() {
		$log = get_option( 'dxps_event_log', array() );
		if ( ! is_array( $log ) ) $log = array();
		echo '<h2>Matching-field coverage</h2>';
		if ( ! DXPS_Helpers::on( 'event_log' ) ) {
			echo '<p>The event log is off. Turn on <strong>Event log</strong> on the Events &amp; Parameters tab, browse the shop and place a test order, then come back here.</p>';
		}
		if ( ! $log ) { echo '<p>No server events recorded yet.</p>'; return; }

		$fields = array( 'em' => 'Email', 'ph' => 'Phone', 'fn' => 'First name', 'ln' => 'Last name', 'ct' => 'City', 'zp' => 'ZIP', 'country' => 'Country', 'external_id' => 'External ID', 'fbp' => 'Browser ID (fbp)', 'fbc' => 'Click ID (fbc)', 'client_ip_address' => 'IP', 'client_user_agent' => 'User agent', 'fb_login_id' => 'FB Login ID' );
		$by = array();
		foreach ( $log as $row ) {
			$e = (string) ( $row['e'] ?? '' );
			if ( '' === $e ) continue;
			if ( ! isset( $by[ $e ] ) ) $by[ $e ] = array( 'n' => 0, 'ok' => 0, 'f' => array() );
			$by[ $e ]['n']++;
			if ( ! empty( $row['ok'] ) ) $by[ $e ]['ok']++;
			foreach ( (array) ( $row['f'] ?? array() ) as $f ) $by[ $e ]['f'][ $f ] = ( $by[ $e ]['f'][ $f ] ?? 0 ) + 1;
		}
		echo '<p>Share of the last ' . count( $log ) . ' Conversions API events that carried each matching field. This shows what DX sent; the Event Match Quality score itself is calculated by Meta in Events Manager. Click ID is only present for visitors who arrived from a Meta ad.</p>';
		echo '<div style="overflow-x:auto"><table class="widefat striped"><thead><tr><th>Event</th><th>Sent</th><th>Accepted</th>';
		foreach ( $fields as $fl ) echo '<th>' . esc_html( $fl ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $by as $e => $d ) {
			echo '<tr><td><strong>' . esc_html( $e ) . '</strong></td><td>' . (int) $d['n'] . '</td><td>' . (int) round( 100 * $d['ok'] / $d['n'] ) . '%</td>';
			foreach ( $fields as $fk => $fl ) echo '<td>' . (int) round( 100 * ( $d['f'][ $fk ] ?? 0 ) / $d['n'] ) . '%</td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';

		echo '<h2>Recent server events</h2><table class="widefat striped"><thead><tr><th>Time</th><th>Event</th><th>Result</th><th>Value</th><th>Matching fields sent</th></tr></thead><tbody>';
		foreach ( array_slice( array_reverse( $log ), 0, 50 ) as $row ) {
			echo '<tr><td>' . esc_html( wp_date( 'Y-m-d H:i:s', (int) ( $row['t'] ?? 0 ) ) ) . '</td><td>' . esc_html( (string) ( $row['e'] ?? '' ) ) . '</td><td>' . ( ! empty( $row['ok'] ) ? '<span style="color:#00a32a">accepted</span>' : '<span style="color:#d63638">' . esc_html( (string) ( $row['s'] ?? 'failed' ) ) . '</span>' ) . '</td><td>' . ( ! empty( $row['v'] ) ? 'yes' : '—' ) . '</td><td>' . esc_html( implode( ', ', array_map( 'sanitize_key', (array) ( $row['f'] ?? array() ) ) ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<form method="post" style="margin-top:12px">'; wp_nonce_field( 'dxps_settings' );
		echo '<button type="submit" name="dxps_clear_log" value="1" class="button">Clear log</button></form>';
	}

	/* ================= Recommended ================= */

	private static function tab_recommended() {
		$rows = array(
			array( 'Meta Pixel + CAPI', 'meta_enabled', 1, 'Enable only when DX is the sole Meta browser owner; pause overlapping GTM Meta tags first.' ),
			array( 'Meta CAPI token filled', '_meta_token', null, 'Required for server events. Without it only the browser pixel fires.' ),
			array( 'Meta test event code empty (production)', '_meta_test', null, 'Leave filled only while testing.' ),
			array( 'GA4 (browser + Measurement Protocol)', 'ga4_enabled', 1, 'Use DX direct Google tracking or GTM ownership intentionally; do not enable duplicate browser/server event senders.' ),
			array( 'GA4 API secret filled', '_ga4_secret', null, 'Required for Measurement Protocol.' ),
			array( 'Google Ads + Enhanced Conversions', 'gads_enabled', 1, 'Enable direct Ads only with valid IDs/labels and a single conversion owner; match-quality gains vary.' ),
			array( 'GTM', 'gtm_enabled', 0, 'Keep ON if GTM owns Google tracking; GTM-only suppresses DX direct Google dispatch, not DX Meta.' ),
			array( 'Consent Mode v2', 'consent_mode', 1, 'ON — mandatory for your EU traffic (HR/SI/DE); BA/RS unaffected.' ),
			array( 'Purchase trigger = order status', '_purchase_status', null, 'COD store: fire on Completed, not thank-you — kills conversions from failed pouzeće orders.' ),
			array( 'Server relay', 'server_relay', 1, 'Uses validated, consent-allowed matching fields and minimized Meta parameters; actual coverage depends on available customer data.' ),
			array( 'Lead clicks (WhatsApp/tel/mail)', 'track_leads', 1, 'ON — WhatsApp is your biggest untracked conversion path.' ),
			array( 'Site search', 'track_search', 1, 'ON — search terms are high-intent audience fuel.' ),
			array( 'PageScroll', 'track_scroll', 1, 'ON — engaged-visitor audiences for retargeting.' ),
			array( 'TimeOnPage', 'track_time', 1, 'ON at 30s — pairs with PageScroll for engagement audiences.' ),
			array( 'Internal clicks', 'track_clicks', 1, 'ON — navigation intent signal; capped at 15/pageview.' ),
			array( 'Excluded coupons contains "alopeci"', '_coupons', null, 'Keep the exclusion; DX also suppresses recognized sensitive-health contexts.' ),
			array( 'Remember matching data', '_retention', null, 'Set 30 to 90 days so returning visitors keep their matching data. Biggest match-quality lever for anonymous traffic.' ),
			array( 'Detect customer data in all forms', 'am_all_forms', 1, 'ON — contact and booking forms then feed matching data too.' ),
			array( 'Form leads', 'track_form_leads', 1, 'ON — form submissions reach Meta as Lead.' ),
			array( 'Phone default country', 'phone_default_country', 1, 'ON — local-format phone numbers are otherwise dropped.' ),
			array( 'Browser ID fallback (_fbp)', 'fbp_fallback', 1, 'ON — keeps the browser ID when Meta&#8217;s script is blocked.' ),
			array( 'Block bots', 'block_bots', 1, 'ON — keeps crawler events out of Meta&#8217;s learning.' ),
			array( 'Excluded roles set', '_roles', null, 'Add administrator, shop_manager so staff and test orders are not tracked.' ),
			array( 'Event log', 'event_log', 0, 'OFF in production; ON while checking the Diagnostics tab.' ),
			array( 'Debug log', 'debug_log', 0, 'OFF in production; ON only while testing.' ),
		);
		echo '<h2>Recommended configuration for eynna-hair.ba</h2><table class="widefat striped"><thead><tr><th>Setting</th><th>Status</th><th>Why</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			list( $label, $key, $want, $why ) = $r;
			$ok = null;
			if ( null !== $want ) {
				$ok = ( DXPS_Helpers::on( $key ) ? 1 : 0 ) === $want;
			} elseif ( '_meta_token' === $key ) { $ok = (bool) DXPS_Helpers::opt( 'meta_capi_token' ); }
			elseif ( '_meta_test' === $key )        { $ok = ! DXPS_Helpers::opt( 'meta_test_code' ); }
			elseif ( '_ga4_secret' === $key ) { $ok = (bool) DXPS_Helpers::opt( 'ga4_api_secret' ); }
			elseif ( '_purchase_status' === $key )  { $ok = 'status' === DXPS_Helpers::opt( 'purchase_trigger', 'status' ); }
			elseif ( '_retention' === $key )        { $ok = (int) DXPS_Helpers::opt( 'am_retention_days', 0 ) >= 30; }
			elseif ( '_roles' === $key )            { $ok = '' !== (string) DXPS_Helpers::opt( 'exclude_roles' ); }
			elseif ( '_coupons' === $key )          { $ok = false !== strpos( strtolower( DXPS_Helpers::opt( 'excluded_coupons', 'alopeci' ) ), 'alopeci' ); }
			$badge = $ok
				? '<span style="color:#00a32a;font-weight:600">✓ OK</span>'
				: '<span style="color:#d63638;font-weight:600">✗ Adjust</span>';
			echo '<tr><td>' . esc_html( html_entity_decode( $label, ENT_QUOTES, 'UTF-8' ) ) . '</td><td>' . $badge . '</td><td>' . esc_html( html_entity_decode( $why, ENT_QUOTES, 'UTF-8' ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<h2>Outside the plugin</h2><ol>
			<li><strong>Cloudflare Google Tag Gateway</strong> — dashboard → Engagement → Google tag gateway → ON, with its "automated script setup" OFF.</li>
			<li><strong>Deactivate PixelYourSite</strong> when this plugin takes over the live pixel ID (double events otherwise).</li>
			<li><strong>Google Ads attribution</strong> — campaigns to 7-day click / 1-day view (longer windows were removed Jan 2026).</li>
			<li><strong>Facebook Login ID</strong> — detected automatically from your social-login plugin user meta and sent with CAPI events (the ~0.16–10% match-quality lift Meta shows in Events Manager).</li>
			<li><strong>Cookie banner</strong> — wire accept/decline to <code>dxpsConsent(\'granted\')</code> / <code>dxpsConsent(\'denied\')</code>.</li></ol>';
	}
}
