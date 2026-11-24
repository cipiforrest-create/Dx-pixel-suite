<?php
if ( 'cli' !== PHP_SAPI ) exit; // never runs over the web
ob_start(); // keeps headers unsent, so setcookie() in the code under test works; printed at the end
require __DIR__ . '/bootstrap.php';
$fail = 0;
function ok( $cond, $label ) { global $fail; echo ( $cond ? '  ok   ' : '  FAIL ' ) . $label . "\n"; if ( ! $cond ) $fail++; }
function settings( $o ) { $GLOBALS['t']['options']['dxps_settings'] = $o; DXPS_Helpers::reset_options(); }

/* ---------------- product feed ---------------- */
echo "Feed rows\n";
settings( array( 'product_id_format' => 'id' ) );
$GLOBALS['t']['terms'] = array( 10 => array( 'name' => 'Hair extensions', 'parent' => 0 ), 11 => array( 'name' => 'Clip-in', 'parent' => 10 ), 12 => array( 'name' => 'Sale', 'parent' => 0 ) );
$parent = new WC_Product( array( 'id' => 100, 'type' => 'variable', 'name' => 'Clip-in set', 'description' => '<p>Soft remy hair.</p><p>Seven wefts.</p>', 'cats' => array( 12, 11 ), 'image_id' => 5, 'gallery' => array( 6 ), 'total_sales' => 40, 'created' => time() - 5 * 86400 ) );
$var    = new WC_Product( array( 'id' => 101, 'type' => 'variation', 'parent_id' => 100, 'name' => 'Clip-in set - Smeđa, 50 cm', 'price' => '120', 'regular_price' => '150', 'sale' => true, 'image_id' => 7, 'attrs' => array( 'pa_boja' => 'Smeđa', 'pa_duzina' => '50 cm' ), 'stock' => 1 ) );
$bare   = new WC_Product( array( 'id' => 102, 'name' => 'Hair brush', 'price' => '30', 'regular_price' => '30', 'image_id' => 8, 'cats' => array( 10 ), 'backorder' => true ) );
$GLOBALS['t']['products'] = array( 100 => $parent, 101 => $var, 102 => $bare );
$cfg = array_merge( DXPS_Feeds::defaults(), array( 'id' => 'f1', 'token' => 'tok', 'name' => 'Google', 'auto_labels' => 1, 'link_params' => 'utm_source=google&utm_content={id}' ) );

$r = DXPS_Feed_Product::build( $var, $cfg );
ok( empty( $r['errors'] ), 'variation builds without errors: ' . implode( ',', $r['errors'] ?? array() ) );
ok( 'Hair extensions > Clip-in' === $r['row']['product_type'], 'product_type is the deepest category path' );
ok( 'Smeđa' === $r['row']['color'] && '50 cm' === $r['row']['size'], 'localised attribute slugs fill color and size' );
ok( '100' === $r['row']['item_group_id'] && '101' === $r['row']['id'], 'variation id / item_group_id' );
ok( '150.00 BAM' === $r['row']['price'] && '120.00 BAM' === $r['row']['sale_price'], 'regular and sale price' );
ok( in_array( 'http://shop.test/wp-content/uploads/img-5.jpg', $r['row']['additional_image_link'], true ), 'parent photo kept as an additional image' );
ok( 'price-100-250' === $r['row']['custom_label_0'] && 'on-sale' === $r['row']['custom_label_1'] && 'bestseller' === $r['row']['custom_label_2'] && 'new-arrival' === $r['row']['custom_label_3'] && 'low-stock' === $r['row']['custom_label_4'], 'automatic custom labels' );
ok( 'http://shop.test/product/101/?utm_source=google&utm_content=101' === $r['row']['link'], 'tracking parameters on the link: ' . $r['row']['link'] );
ok( '1' === $r['row']['quantity_to_sell_on_facebook'], 'stock quantity for Meta' );
ok( ! in_array( 'variant_attributes_missing', $r['warnings'], true ), 'no variant-attribute warning' );

$r = DXPS_Feed_Product::build( $bare, $cfg );
ok( in_array( 'future_availability_date_required', $r['errors'], true ), 'strict: undated backorder is reported' );
ok( 'Hair brush' === $r['row']['description'] && in_array( 'description_missing_fallback_used', $r['warnings'], true ), 'missing description falls back to the title with a warning' );
$r = DXPS_Feed_Product::build( $bare, array( 'backorder_mode' => 'estimate', 'backorder_days' => 10 ) + $cfg );
ok( empty( $r['errors'] ) && 'backorder' === $r['row']['availability'] && strtotime( $r['row']['availability_date'] ) > time() + 9 * 86400, 'estimate: backorder gets the lead-time date' );
$r = DXPS_Feed_Product::build( $bare, array( 'backorder_mode' => 'in_stock' ) + $cfg );
ok( empty( $r['errors'] ) && 'in_stock' === $r['row']['availability'], 'in_stock policy' );
$meta_cfg = array( 'platform' => 'meta', 'format' => 'csv' ) + $cfg;
$r = DXPS_Feed_Product::build( $bare, $meta_cfg );
ok( empty( $r['errors'] ), 'Meta does not require an availability date' );
$fp = fopen( 'php://memory', 'w+' ); DXPS_Feeds::write_row( $fp, $r['row'], $meta_cfg ); rewind( $fp ); $csv = stream_get_contents( $fp );
ok( false !== strpos( $csv, 'available for order' ), 'Meta backorder is written as "available for order"' );
ok( in_array( 'quantity_to_sell_on_facebook', DXPS_Feeds::fields( $meta_cfg ), true ) && ! in_array( 'quantity_to_sell_on_facebook', DXPS_Feeds::fields( $cfg ), true ), 'Meta-only column stays out of the Google feed' );
$fp = fopen( 'php://memory', 'w+' ); $r = DXPS_Feed_Product::build( $var, $cfg ); DXPS_Feeds::write_row( $fp, $r['row'], $cfg ); rewind( $fp );
ok( false !== @simplexml_load_string( '<rss xmlns:g="http://base.google.com/ns/1.0">' . stream_get_contents( $fp ) . '</rss>' ), 'XML item is well formed' );

$s = DXPS_Feed_Options::sanitize( array( 'price_tiers' => '500, 100,abc', 'link_params' => '?utm_source=meta&utm_medium=paid social', 'backorder_mode' => 'estimate', 'backorder_days' => '9999' ), array( 'platform' => 'google' ) );
ok( ! is_wp_error( $s ) && array( 100, 500 ) === $s['price_tiers'] && 'utm_source=meta&utm_medium=paid_social' === $s['link_params'] && 365 === $s['backorder_days'], 'feed option sanitising' );
ok( is_wp_error( DXPS_Feed_Options::sanitize( array( 'link_params' => 'x=<script>' ), array( 'platform' => 'google' ) ) ), 'unsafe link parameters are rejected' );

/* ---------------- identity ---------------- */
echo "Identity\n";
$_COOKIE['pbid'] = str_repeat( 'ab', 16 );
ok( hash( 'sha256', $_COOKIE['pbid'] ) === DXPS_Helpers::external_id(), '32-character browser ID is hashed' );
$_COOKIE['pbid'] = strtoupper( hash( 'sha256', 'x' ) );
ok( hash( 'sha256', 'x' ) === DXPS_Helpers::external_id(), '64-character browser ID is used as it is (matches the pixel)' );
ok( '' === DXPS_Helpers::safe_label( 'Perike za alopeciju' ) && 'Clip-in' === DXPS_Helpers::safe_label( 'Clip-in' ), 'health-related labels are never used' );

/* 2.3.0: browser-held matching cookie, merge order, relay identity, visitor IP, phone country */
echo "Match quality (2.3.0)\n";
settings( array( 'auto_am' => 1, 'consent_mode' => 0 ) );
$em = hash( 'sha256', 'guest@example.com' ); $ph = hash( 'sha256', '38761123456' );
$_COOKIE = array( 'pbid' => str_repeat( 'ef', 16 ), '_dxps_ud' => 'em.' . $em . '~ph.' . $ph . '~fn.NOT-A-HASH~xx.' . $em );
$GLOBALS['t']['user'] = 0;
$ud = DXPS_Helpers::cookie_user_data();
ok( $ud === array( 'em' => $em, 'ph' => $ph ), 'matching cookie: only 64-hex values of known keys are read' );
ok( 'em.' . $em . '~ph.' . $ph === DXPS_Helpers::ud_cookie_value( array( 'em' => $em, 'ph' => $ph, 'client_ip_address' => '1.2.3.4' ) ), 'cookie value holds matching hashes only' );
$_COOKIE['_dxps_ud'] = str_repeat( 'a', 1300 );
ok( array() === DXPS_Helpers::cookie_user_data(), 'oversized matching cookie is ignored' );
$_COOKIE['_dxps_ud'] = 'em.' . $em . '~ph.' . $ph;
$full = DXPS_Helpers::user_data_full();
ok( $em === ( $full['em'] ?? '' ) && $ph === ( $full['ph'] ?? '' ), 'a returning guest is matched from the browser cookie alone' );
$GLOBALS['t']['user'] = 7; $GLOBALS['t']['transients'] = array();
$full = DXPS_Helpers::user_data_full();
ok( hash( 'sha256', 'jane@example.com' ) === $full['em'] && ! isset( $full['ph'] ), 'cookie of a different person (other email) is never mixed with the account' );
settings( array( 'auto_am' => 1, 'consent_mode' => 1 ) );
ok( array() === DXPS_Helpers::cookie_user_data(), 'no consent: the matching cookie is not used' );
settings( array( 'auto_am' => 1, 'consent_mode' => 0 ) );

define( 'LOGGED_IN_COOKIE', 'wordpress_logged_in_test' ); define( 'REST_REQUEST', true );
$GLOBALS['t']['user'] = 0; $GLOBALS['t']['auth_user'] = 42; $_COOKIE = array( 'pbid' => str_repeat( 'ef', 16 ), LOGGED_IN_COOKIE => 'x' );
ok( 42 === DXPS_Helpers::visitor_user_id(), 'relay without a valid nonce still recognises the signed-in customer' );
$ids = DXPS_Helpers::external_ids();
ok( 2 === count( $ids ) && in_array( hash( 'sha256', 'dxps.user.42' ), $ids, true ), 'account external ID is sent from the relay too' );
ok( hash( 'sha256', 'user42@example.com' ) === ( DXPS_Helpers::user_data_current()['em'] ?? '' ), 'account email reaches relayed events' );
$acc = DXPS_Helpers::account_user_data( 42 );
ok( isset( $acc['em'], $acc['fn'], $acc['ln'] ) && hash( 'sha256', 'anamarić' ) !== $acc['fn'] && hash( 'sha256', 'ana' ) === $acc['fn'], 'login / sign-up events carry profile names, not just the email' );
$GLOBALS['t']['auth_user'] = 0; $_COOKIE = array();
ok( 0 === DXPS_Helpers::visitor_user_id(), 'no logged-in cookie, no account' );

$srv = $_SERVER;
$_SERVER['REMOTE_ADDR'] = '172.70.1.1'; $_SERVER['HTTP_CF_CONNECTING_IP'] = '89.146.10.20';
ok( '89.146.10.20' === DXPS_Helpers::client_ip() && 'cloudflare' === DXPS_Helpers::ip_setup(), 'behind Cloudflare the visitor address is used, not the edge server' );
$_SERVER['REMOTE_ADDR'] = '2a06:98c1:3120::1';
ok( '89.146.10.20' === DXPS_Helpers::client_ip(), 'Cloudflare IPv6 edge recognised' );
$_SERVER['REMOTE_ADDR'] = '89.146.10.30'; $_SERVER['HTTP_CF_CONNECTING_IP'] = '8.8.8.8'; $_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.4.4';
ok( '89.146.10.30' === DXPS_Helpers::client_ip() && 'direct' === DXPS_Helpers::ip_setup(), 'direct request: forged forwarding headers are ignored' );
$_SERVER['REMOTE_ADDR'] = '10.0.0.5'; unset( $_SERVER['HTTP_CF_CONNECTING_IP'] ); $_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.4.4, 89.146.10.40, 10.0.0.2';
ok( '89.146.10.40' === DXPS_Helpers::client_ip() && 'proxy' === DXPS_Helpers::ip_setup(), 'local proxy: the address it added is used, a forged left-most entry is not' );
$_SERVER['HTTP_X_FORWARDED_FOR'] = '89.146.10.50, 172.70.1.1'; $_SERVER['HTTP_CF_CONNECTING_IP'] = '89.146.10.50';
ok( '89.146.10.50' === DXPS_Helpers::client_ip(), 'local proxy behind Cloudflare: visitor address, not the edge' );
$_SERVER = $srv;

settings( array( 'phone_default_country' => 1 ) ); $GLOBALS['t']['locale'] = 'bs_BA';
ok( '+38761123456' === DXPS_Helpers::norm_phone( '061 123 456' ), 'local phone number gets the country from the site language' );
settings( array() );
ok( '' === DXPS_Helpers::norm_phone( '061 123 456' ), 'without the setting a local number is still dropped' );
$GLOBALS['t']['locale'] = 'en_US';

/* ---------------- relay ---------------- */
echo "Relay\n";
settings( array( 'server_relay' => 1, 'meta_enabled' => 1, 'meta_pixel_id' => '123', 'meta_capi_token' => 'tok', 'auto_am' => 1, 'consent_mode' => 0 ) );
$_COOKIE = array( 'pbid' => str_repeat( 'cd', 16 ), '_fbp' => 'fb.1.1700000000000.123456789' ); $_SERVER['REMOTE_ADDR'] = '203.0.113.9'; $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
$GLOBALS['t']['user'] = 7;
$h = array( 'origin' => 'http://shop.test' );
$ev = function ( $n, $id, $p = array() ) { return array( 'name' => $n, 'event_id' => $id, 'url' => 'http://shop.test/product/1/?email=a@b.c', 'params' => $p ); };
$GLOBALS['t']['http'] = array(); $GLOBALS['t']['transient_writes'] = 0;
$res = DXPS_Server::relay( new WP_REST_Request( array( 'events' => array( $ev( 'page_view', 'pv.1' ), $ev( 'view_item', 'vi.1', array( 'value' => 120, 'currency' => 'BAM', 'items' => array( array( 'id' => '101', 'price' => 120, 'quantity' => 1 ) ) ) ), $ev( 'purchase', 'purchase.5' ) ) ), $h ) );
$sent = $GLOBALS['t']['http'];
ok( 200 === $res->status && true === $res->data['ok'] && 2 === $res->data['sent'], 'batch accepted, purchase left to the server' );
ok( 1 === count( $sent ) && 2 === count( $sent[0]['body']['data'] ), 'one Conversions API request for the whole batch' );
ok( false !== strpos( $sent[0]['url'], '/v25.0/123/events' ), 'current Graph API version' );
$d = $sent[0]['body']['data'];
ok( 'PageView' === $d[0]['event_name'] && 'ViewContent' === $d[1]['event_name'] && array( '101' ) === $d[1]['custom_data']['content_ids'] && 120 == $d[1]['custom_data']['value'], 'event names and catalogue IDs' );
ok( 'http://shop.test/product/1/' === $d[1]['event_source_url'], 'query string removed from the event URL' );
ok( isset( $d[0]['user_data']['em'], $d[0]['user_data']['fbp'], $d[0]['user_data']['external_id'], $d[0]['user_data']['client_ip_address'] ), 'matching data on every event' );
ok( isset( $res->data['am']['em'] ) && hash( 'sha256', 'jane@example.com' ) === $res->data['am']['em'] && ! isset( $res->data['am']['client_ip_address'] ), 'hashed matching fields returned for the browser pixel' );
ok( $GLOBALS['t']['transient_writes'] <= 2, 'at most two database writes for the batch (rate limit + matching memory): ' . $GLOBALS['t']['transient_writes'] );
$GLOBALS['t']['transient_writes'] = 0;
DXPS_Server::relay( new WP_REST_Request( $ev( 'scroll', 'sc.1', array( 'percent_scrolled' => 50 ) ), $h ) );
ok( 2 === count( $GLOBALS['t']['http'] ) && 'PageScroll' === $GLOBALS['t']['http'][1]['body']['data'][0]['event_name'], 'single-event body from cached pages still works' );
ok( 1 === $GLOBALS['t']['transient_writes'], 'unchanged matching data is not rewritten' );
ok( 400 === DXPS_Server::relay( new WP_REST_Request( array( 'events' => array( $ev( 'made_up', 'x.1' ) ) ), $h ) )->status, 'unknown event names are rejected' );
ok( 403 === DXPS_Server::relay( new WP_REST_Request( array( 'events' => array( $ev( 'scroll', 'x.2' ) ) ), array( 'origin' => 'http://evil.test' ) ) )->status, 'cross-origin requests are rejected' );
$n = count( $GLOBALS['t']['http'] );
$res = DXPS_Server::relay( new WP_REST_Request( array( 'events' => array( array( 'name' => 'scroll', 'event_id' => 'x.3', 'url' => 'http://shop.test/alopecija/', 'params' => array() ) ) ), $h ) );
ok( $n === count( $GLOBALS['t']['http'] ) && in_array( 'sensitive_context', $res->data['skipped'], true ), 'health-related pages are never relayed' );
settings( array( 'server_relay' => 1, 'meta_enabled' => 1, 'meta_pixel_id' => '123', 'meta_capi_token' => 'tok', 'consent_mode' => 1 ) );
$res = DXPS_Server::relay( new WP_REST_Request( array( 'events' => array( $ev( 'scroll', 'x.4' ) ) ), $h ) );
ok( $n === count( $GLOBALS['t']['http'] ) && 'disabled_or_consent' === $res->data['skipped'], 'no consent, no relay' );

/* ---------------- 2.3.1: relay acceptance, counters, IPv6, click ID from the referrer ---------------- */
echo "Diagnostics fixes (2.3.1)\n";
ok( DXPS_Helpers::same_site_origin( 'http://www.shop.test/page/' ) && DXPS_Helpers::same_site_origin( 'http://shop.test' ), 'www and bare domain are the same site' );
ok( ! DXPS_Helpers::same_site_origin( 'http://evil.test' ) && ! DXPS_Helpers::same_site_origin( 'http://shop.test.evil.test' ) && ! DXPS_Helpers::same_site_origin( 'http://shop.test:8080' ) && ! DXPS_Helpers::same_site_origin( 'https://shop.test' ) && ! DXPS_Helpers::same_site_origin( 'javascript:alert(1)' ), 'other hosts, ports and schemes are refused' );
settings( array( 'server_relay' => 1, 'meta_enabled' => 1, 'meta_pixel_id' => '123', 'meta_capi_token' => 'tok', 'auto_am' => 1, 'consent_mode' => 0 ) );
$GLOBALS['t']['options']['dxps_relay_stats'] = array(); $GLOBALS['t']['transients'] = array();
$_COOKIE = array( 'pbid' => str_repeat( 'ab', 16 ) ); $_SERVER['REMOTE_ADDR'] = '89.146.10.30';
$n0 = count( $GLOBALS['t']['http'] );
$res = DXPS_Server::relay( new WP_REST_Request( array( 'events' => array( $ev( 'scroll', 'w.1', array( 'percent_scrolled' => 25 ) ) ) ), array( 'origin' => 'http://www.shop.test' ) ) );
ok( 200 === $res->status && count( $GLOBALS['t']['http'] ) === $n0 + 1, 'relay from the www address is delivered (was refused before)' );
DXPS_Server::relay( new WP_REST_Request( array( 'events' => array( $ev( 'scroll', 'w.2' ) ) ), array( 'origin' => 'http://evil.test' ) ) );
ok( 1 === ( DXPS_Helpers::relay_stats()['origin'] ?? 0 ), 'refused requests are counted by reason' );
$GLOBALS['t']['transients'][ 'dxps_rl_' . md5( str_repeat( 'ab', 16 ) . '|89.146.10.30|' . floor( time() / 60 ) ) ] = 240;
ok( 403 === DXPS_Server::relay( new WP_REST_Request( array( 'events' => array( $ev( 'scroll', 'w.3' ) ) ), $h ) )->status && 1 === ( DXPS_Helpers::relay_stats()['rate'] ?? 0 ), 'a browser over its rate limit is refused and counted' );
$_COOKIE['pbid'] = str_repeat( 'cd', 16 );
ok( 200 === DXPS_Server::relay( new WP_REST_Request( array( 'events' => array( $ev( 'scroll', 'w.4' ) ) ), $h ) )->status, 'another browser on the same IP address is not throttled' );

settings( array( 'ip6_lookup' => 1, 'consent_mode' => 0 ) );
$srv = $_SERVER; $_SERVER['REMOTE_ADDR'] = '89.146.10.30';
$_COOKIE = array( '_dxps_ip' => rawurlencode( '2a02:810d:4b00:1::77' ) );
ok( '2a02:810d:4b00:1::77' === DXPS_Helpers::client_ip(), 'IPv4 connection: the browser-reported IPv6 address is sent' );
foreach ( array( 'fd00::1', '::1', '89.146.10.99', '2a02:zz::1', str_repeat( 'a', 60 ) ) as $bad ) {
	$_COOKIE['_dxps_ip'] = $bad;
	ok( '89.146.10.30' === DXPS_Helpers::client_ip(), 'ignored browser IP value: ' . substr( $bad, 0, 20 ) );
}
$_COOKIE['_dxps_ip'] = '2a02:810d:4b00:1::77'; $_SERVER['REMOTE_ADDR'] = '2a02:810d:4b00:1::88';
ok( '2a02:810d:4b00:1::88' === DXPS_Helpers::client_ip(), 'an IPv6 connection keeps its own address' );
settings( array( 'ip6_lookup' => 0 ) ); $_SERVER['REMOTE_ADDR'] = '89.146.10.30';
ok( '89.146.10.30' === DXPS_Helpers::client_ip(), 'setting off: browser IP value not used' );
settings( array( 'ip6_lookup' => 1, 'consent_mode' => 1 ) );
ok( '89.146.10.30' === DXPS_Helpers::client_ip(), 'no consent: browser IP value not used' );

settings( array() ); $_COOKIE = array(); unset( $_GET['fbclid'] );
$_SERVER['HTTP_REFERER'] = 'http://www.shop.test/landing/?utm_source=fb&fbclid=RefClick12345';
ok( 1 === preg_match( '/^fb\.1\.\d{13}\.RefClick12345$/', DXPS_Helpers::fbc() ), 'click ID recovered from the previous page of this site' );
$_SERVER['HTTP_REFERER'] = 'http://evil.test/?fbclid=Forged12345';
ok( '' === DXPS_Helpers::fbc(), 'click ID from a foreign referrer is ignored' );
$_SERVER = $srv;

/* ---------------- 2.4.0: location fill-in, order-email recognition ---------------- */
echo "Identity sources (2.4.0)\n";
settings( array( 'geo_enrich' => 'full', 'auto_am' => 1, 'consent_mode' => 0 ) );
$srv = $_SERVER; $GLOBALS['t']['user'] = 0; $GLOBALS['t']['transients'] = array();
$_SERVER = array_merge( $_SERVER, array( 'REMOTE_ADDR' => '172.70.1.1', 'HTTP_CF_CONNECTING_IP' => '89.146.10.20', 'HTTP_CF_IPCOUNTRY' => 'BA', 'HTTP_CF_IPCITY' => 'Sarajevo', 'HTTP_CF_REGION_CODE' => 'BIH', 'HTTP_CF_POSTAL_CODE' => '71000' ) );
$geo = DXPS_Helpers::geo_hashes();
ok( hash( 'sha256', 'sarajevo' ) === ( $geo['ct'] ?? '' ) && hash( 'sha256', '71000' ) === ( $geo['zp'] ?? '' ) && hash( 'sha256', 'bih' ) === ( $geo['st'] ?? '' ) && hash( 'sha256', 'ba' ) === ( $geo['country'] ?? '' ), 'through Cloudflare: city, region, ZIP and country are filled in' );
$_SERVER['REMOTE_ADDR'] = '89.146.10.30';
ok( array() === DXPS_Helpers::geo_hashes(), 'direct request: forged Cloudflare location headers are ignored' );
$_SERVER['REMOTE_ADDR'] = '172.70.1.1';
$_COOKIE = array( '_dxps_ud' => 'country.' . hash( 'sha256', 'de' ) );
ok( ! isset( DXPS_Helpers::user_data_full()['country'] ), 'anonymous visitor: a guessed country gives way to the real (Cloudflare) one' );
$_COOKIE = array( '_dxps_ud' => 'em.' . hash( 'sha256', 'a@b.ba' ) . '~ct.' . hash( 'sha256', 'mostar' ) );
settings( array( 'server_relay' => 1, 'meta_enabled' => 1, 'meta_pixel_id' => '123', 'meta_capi_token' => 'tok', 'auto_am' => 1, 'consent_mode' => 0, 'geo_enrich' => 'full' ) );
$n0 = count( $GLOBALS['t']['http'] );
DXPS_Server::relay( new WP_REST_Request( array( 'events' => array( $ev( 'scroll', 'g.1' ) ) ), $h ) );
$ud = $GLOBALS['t']['http'][ $n0 ]['body']['data'][0]['user_data'] ?? array();
ok( hash( 'sha256', 'mostar' ) === ( $ud['ct'] ?? '' ) && hash( 'sha256', '71000' ) === ( $ud['zp'] ?? '' ), 'customer data wins over the location fill-in, missing fields are filled' );

$tok = DXPS_Helpers::identity_token( 55 );
ok( 55 === DXPS_Helpers::identity_order_id( $tok ), 'order-email token: signed and readable' );
ok( 0 === DXPS_Helpers::identity_order_id( substr( $tok, 0, -1 ) . ( 'a' === substr( $tok, -1 ) ? 'b' : 'a' ) ) && 0 === DXPS_Helpers::identity_order_id( '56' . substr( $tok, 2 ) ) && 0 === DXPS_Helpers::identity_order_id( DXPS_Helpers::identity_token( 55, -60 ) ) && 0 === DXPS_Helpers::identity_order_id( 'x' ), 'tampered, other-order, expired and malformed tokens are refused' );
$eid64 = hash( 'sha256', 'checkout-browser' );
$GLOBALS['t']['orders'] = array(
	55 => new WC_Order( array( 'id' => 55, 'email' => 'Kupac@Example.com', 'phone' => '061 222 333', 'first' => 'Lejla', 'last' => 'Begić', 'city' => 'Tuzla', 'zip' => '75000', 'country' => 'BA', 'meta' => array( '_dxps_marketing_allowed' => '1', '_dxps_eid' => $eid64 ) ) ),
	56 => new WC_Order( array( 'id' => 56, 'email' => 'no@example.com', 'meta' => array( '_dxps_marketing_allowed' => '0' ) ) ),
);
settings( array( 'auto_am' => 1, 'email_identity' => 1, 'consent_mode' => 0 ) );
$GLOBALS['t']['options']['dxps_relay_stats'] = array(); $_COOKIE = array();
$res = DXPS_Server::identify( new WP_REST_Request( array( 'token' => $tok ), $h ) );
$ud  = DXPS_Helpers::cookie_user_data();
ok( true === $res->data['ok'] && hash( 'sha256', 'kupac@example.com' ) === ( $ud['em'] ?? '' ) && hash( 'sha256', '38761222333' ) === ( $ud['ph'] ?? '' ) && hash( 'sha256', 'lejla' ) === ( $ud['fn'] ?? '' ), 'email link: the buyer is recognised (hashed email, phone, name in the browser cookie)' );
ok( $eid64 === ( $_COOKIE['pbid'] ?? '' ) && 1 === count( $res->data ), 'new device takes the checkout browser ID; the response carries no personal data' );
$_COOKIE = array();
$res = DXPS_Server::identify( new WP_REST_Request( array( 'token' => DXPS_Helpers::identity_token( 56 ) ), $h ) );
ok( false === $res->data['ok'] && array() === DXPS_Helpers::cookie_user_data() && 1 === ( DXPS_Helpers::relay_stats()['identity'] ?? 0 ), 'order without marketing consent: no recognition, refusal counted' );
settings( array( 'auto_am' => 1, 'email_identity' => 1, 'consent_mode' => 1 ) );
$res = DXPS_Server::identify( new WP_REST_Request( array( 'token' => $tok ), $h ) );
ok( 'disabled_or_consent' === ( $res->data['skipped'] ?? '' ), 'no consent on the site: no recognition' );
$_SERVER = $srv; $_COOKIE = array();

$html = '<p><a href="http://shop.test/my-account/view-order/55/?a=1&amp;b=2#top">Narudžba</a> <a href="https://evil.test/x">Drugo</a> <a href=\'http://www.shop.test/\'>Shop</a></p>';
$out = DXPS_Email_Identity::add_token( $html, 'TOKEN1' );
ok( false !== strpos( $out, 'href="http://shop.test/my-account/view-order/55/?a=1&amp;b=2&amp;dxr=TOKEN1#top"' ) && false !== strpos( $out, "href='http://www.shop.test/?dxr=TOKEN1'" ) && false !== strpos( $out, 'href="https://evil.test/x"' ), 'email links: token added to shop links only, anchors and parameters kept' );
settings( array( 'email_identity' => 1 ) );
$customer_mail = new class( $GLOBALS['t']['orders'][55] ) { public $object; function __construct( $o ) { $this->object = $o; } function is_customer_email() { return true; } };
$admin_mail    = new class( $GLOBALS['t']['orders'][55] ) { public $object; function __construct( $o ) { $this->object = $o; } function is_customer_email() { return false; } };
DXPS_Email_Identity::remember( '', $customer_mail );
ok( false !== strpos( DXPS_Email_Identity::rewrite( $html ), 'dxr=55.' ), 'customer order email gets the token' );
DXPS_Email_Identity::remember( '', $admin_mail );
ok( $html === DXPS_Email_Identity::rewrite( $html ), 'admin email is left unchanged' );
DXPS_Email_Identity::remember( '', new class( $GLOBALS['t']['orders'][56] ) { public $object; function __construct( $o ) { $this->object = $o; } function is_customer_email() { return true; } } );
ok( $html === DXPS_Email_Identity::rewrite( $html ), 'order without marketing consent gets no token' );
$GLOBALS['t']['user'] = 7; // the rendered-page checks below expect the signed-in customer

/* ---------------- 2.4.1: hosts that restore the visitor IP; MaxMind fallback ---------------- */
echo "Location sources (2.4.1)\n";
settings( array( 'geo_enrich' => 'full', 'auto_am' => 1, 'consent_mode' => 0 ) );
$srv = $_SERVER; $GLOBALS['t']['transients'] = array(); $_COOKIE = array(); $u0 = $GLOBALS['t']['user']; $GLOBALS['t']['user'] = 0;
$_SERVER = array_merge( $srv, array( 'REMOTE_ADDR' => '89.146.10.20', 'HTTP_CF_RAY' => '8f1a2b3c4d5e6f70-SJJ', 'HTTP_CF_CONNECTING_IP' => '89.146.10.20', 'HTTP_CF_IPCOUNTRY' => 'BA', 'HTTP_CF_IPCITY' => 'Zenica', 'HTTP_CF_REGION_CODE' => 'ZDK', 'HTTP_CF_POSTAL_CODE' => '72000' ) );
$geo = DXPS_Helpers::geo_hashes();
ok( 'cloudflare_restored' === DXPS_Helpers::ip_setup() && hash( 'sha256', 'zenica' ) === ( $geo['ct'] ?? '' ) && hash( 'sha256', '72000' ) === ( $geo['zp'] ?? '' ), 'host restored the Cloudflare visitor IP: location fill-in works' );
ok( '89.146.10.20' === DXPS_Helpers::client_ip(), 'restored visitor address is sent as it is' );
unset( $_SERVER['HTTP_CF_RAY'] );
ok( 'direct' === DXPS_Helpers::ip_setup() && ! isset( DXPS_Helpers::geo_hashes()['ct'] ), 'without CF-Ray the headers are not trusted' );
$_SERVER['HTTP_CF_RAY'] = '8f1a2b3c4d5e6f70-SJJ'; $_SERVER['HTTP_CF_CONNECTING_IP'] = '8.8.8.8';
ok( 'direct' === DXPS_Helpers::ip_setup() && ! isset( DXPS_Helpers::geo_hashes()['ct'] ), 'visitor address not matching the connection: not trusted' );
$_SERVER = array_merge( $srv, array( 'REMOTE_ADDR' => '89.146.10.21' ) );
$GLOBALS['t']['options']['woocommerce_maxmind_geolocation_settings'] = array( 'license_key' => 'k' );
$GLOBALS['t']['maxmind']['89.146.10.21'] = array( 'country' => 'BA', 'state' => 'BIH', 'city' => 'Banja Luka', 'postcode' => '78000' );
$GLOBALS['t']['geo_calls'] = 0;
$geo = DXPS_Helpers::geo_hashes(); DXPS_Helpers::geo_hashes();
ok( 'maxmind' === DXPS_Helpers::geo_source() && hash( 'sha256', 'banjaluka' ) === ( $geo['ct'] ?? '' ) && hash( 'sha256', '78000' ) === ( $geo['zp'] ?? '' ) && hash( 'sha256', 'ba' ) === ( $geo['country'] ?? '' ), 'no Cloudflare: WooCommerce MaxMind fills in the location' );
ok( 1 === $GLOBALS['t']['geo_calls'], 'one database lookup per visitor IP (cached)' );
settings( array( 'server_relay' => 1, 'meta_enabled' => 1, 'meta_pixel_id' => '123', 'meta_capi_token' => 'tok', 'auto_am' => 1, 'consent_mode' => 0, 'geo_enrich' => 'full' ) );
$_COOKIE = array( '_dxps_ud' => 'em.' . hash( 'sha256', 'x@y.ba' ) . '~ct.' . hash( 'sha256', 'bihac' ) );
$n0 = count( $GLOBALS['t']['http'] );
DXPS_Server::relay( new WP_REST_Request( array( 'events' => array( $ev( 'page_view', 'm.1' ) ) ), $h ) );
$ud = $GLOBALS['t']['http'][ $n0 ]['body']['data'][0]['user_data'] ?? array();
ok( hash( 'sha256', 'bihac' ) === ( $ud['ct'] ?? '' ) && hash( 'sha256', '78000' ) === ( $ud['zp'] ?? '' ), 'relay: customer city wins, ZIP from MaxMind' );
unset( $GLOBALS['t']['options']['woocommerce_maxmind_geolocation_settings'] );
ok( 'none' === DXPS_Helpers::geo_source(), 'nothing configured: no location source' );
$_SERVER = $srv; $_COOKIE = array(); $GLOBALS['t']['user'] = $u0;

/* ---------------- rendered page (consumed by tests/browser.js) ---------------- */
settings( array( 'server_relay' => 1, 'meta_enabled' => 1, 'meta_pixel_id' => '123', 'ga4_enabled' => 1, 'ga4_id' => 'G-TEST', 'gads_enabled' => 1, 'gads_conversion_id' => 'AW-1', 'gads_purchase_label' => 'lbl', 'gads_merchant_id' => '5551234', 'gads_feed_country' => 'ba', 'gads_remarketing' => 1, 'consent_mode' => 0, 'track_scroll' => 0, 'track_time' => 0, 'track_clicks' => 0, 'auto_am' => 1, 'fbp_fallback' => 1 ) );
$_SERVER['REQUEST_URI'] = '/product/1/';
ob_start(); DXPS_Tracking::head_output(); $head = ob_get_clean();
ok( false !== strpos( $head, 'dxpsPageUrl' ) && false !== strpos( $head, '"adsMerchant":{"id":5551234,"country":"BA"}' ) && false !== strpos( $head, '"uid":"u7"' ), 'head output carries the new configuration' );
file_put_contents( __DIR__ . '/.page.html', '<!doctype html><html><head><title>Clip-in set</title>' . $head . '</head><body><script src="/jquery-stub.js"></script><script src="/dxps-front.js"></script></body></html>' );
ok( false !== strpos( $head, hash( 'sha256', 'jane@example.com' ) ) && false !== strpos( $head, "fbq('init','123',am)" ), 'signed-in page: the first pixel init carries the hashed account data' );
ok( false !== strpos( $head, '"udTtl":' ), 'cookie lifetime handed to the browser' );
// Guest page (what a page cache would serve): no customer data in the HTML, the browser cookie supplies it.
$GLOBALS['t']['user'] = 0; $_COOKIE = array();
settings( array( 'server_relay' => 1, 'meta_enabled' => 1, 'meta_pixel_id' => '123', 'consent_mode' => 0, 'track_scroll' => 0, 'track_time' => 0, 'track_clicks' => 0, 'auto_am' => 1, 'am_all_forms' => 1, 'phone_default_country' => 1, 'am_retention_days' => 90, 'fbp_fallback' => 1, 'ip6_lookup' => 1, 'ip6_url' => 'https://api64.ipify.org', 'email_identity' => 1 ) );
$GLOBALS['t']['locale'] = 'bs_BA'; // site language decides the phone country without WooCommerce
ob_start(); DXPS_Tracking::head_output(); $guest = ob_get_clean();
ok( false !== strpos( $guest, '"baseCountry":"ba"' ), 'phone country from the site language reaches the browser' );
ok( false === strpos( $guest, 'jane' ) && false === strpos( $guest, hash( 'sha256', 'jane@example.com' ) ) && false !== strpos( $guest, 'var am={}' ), 'cacheable guest page carries no customer data' );
file_put_contents( __DIR__ . '/.page-guest.html', '<!doctype html><html><head><title>Kontakt</title>' . str_replace( 'http:\/\/shop.test', 'https:\/\/shop.test', $guest ) . '</head><body>'
	. '<form id="kontakt"><label for="f-name">Ime i prezime</label><input id="f-name" name="your-name" type="text">'
	. '<input name="your-email" type="email"><label for="f-tel">Broj telefona</label><input id="f-tel" name="field_3" type="text">'
	. '<input name="your-subject" type="text"><input name="password" type="password"></form>'
	. '<script src="/jquery-stub.js"></script><script src="/dxps-front.js"></script></body></html>' );

echo $fail ? "\n$fail FAILED\n" : "\nAll PHP checks passed\n";
exit( $fail ? 1 : 0 );
