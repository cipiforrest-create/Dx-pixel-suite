<?php
if ( 'cli' !== PHP_SAPI ) exit; // never runs over the web
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

/* ---------------- rendered page (consumed by tests/browser.js) ---------------- */
settings( array( 'server_relay' => 1, 'meta_enabled' => 1, 'meta_pixel_id' => '123', 'ga4_enabled' => 1, 'ga4_id' => 'G-TEST', 'gads_enabled' => 1, 'gads_conversion_id' => 'AW-1', 'gads_purchase_label' => 'lbl', 'gads_merchant_id' => '5551234', 'gads_feed_country' => 'ba', 'gads_remarketing' => 1, 'consent_mode' => 0, 'track_scroll' => 0, 'track_time' => 0, 'track_clicks' => 0, 'auto_am' => 1 ) );
$_SERVER['REQUEST_URI'] = '/product/1/';
ob_start(); DXPS_Tracking::head_output(); $head = ob_get_clean();
ok( false !== strpos( $head, 'dxpsPageUrl' ) && false !== strpos( $head, '"adsMerchant":{"id":5551234,"country":"BA"}' ) && false !== strpos( $head, '"uid":"u7"' ), 'head output carries the new configuration' );
file_put_contents( __DIR__ . '/.page.html', '<!doctype html><html><head><title>Clip-in set</title>' . $head . '</head><body><script src="/jquery-stub.js"></script><script src="/dxps-front.js"></script></body></html>' );

echo $fail ? "\n$fail FAILED\n" : "\nAll PHP checks passed\n";
exit( $fail ? 1 : 0 );
