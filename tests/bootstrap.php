<?php
if ( 'cli' !== PHP_SAPI ) exit; // never runs over the web
/**
 * Minimal WordPress / WooCommerce stand-ins for DX Pixel Suite's regression checks.
 * Not a WordPress test suite: it covers pure logic (feed rows, relay batching, rendered scripts).
 * Run: php tests/run.php
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'DXPS_VERSION', 'test' );
define( 'DXPS_URL', 'http://shop.test/wp-content/plugins/dx-pixel-suite/' );
define( 'DAY_IN_SECONDS', 86400 ); define( 'HOUR_IN_SECONDS', 3600 ); define( 'MINUTE_IN_SECONDS', 60 );
define( 'ENT_XML1_SAFE', 1 );

$GLOBALS['t'] = array( 'options' => array(), 'transients' => array(), 'http' => array(), 'terms' => array(), 'products' => array(), 'user' => 0, 'filters' => array() );

class WP_Error { public $m; function __construct( $c = '', $m = '' ) { $this->m = $m; } function get_error_message() { return $this->m; } }
class WP_REST_Response { public $data, $status, $headers = array(); function __construct( $d = null, $s = 200 ) { $this->data = $d; $this->status = $s; } function header( $k, $v ) { $this->headers[ $k ] = $v; } }
class WP_REST_Request {
	public $body, $headers;
	function __construct( $json, $headers = array() ) { $this->body = json_encode( $json ); $this->headers = $headers; }
	function get_body() { return $this->body; }
	function get_header( $k ) { return $this->headers[ $k ] ?? ''; }
	function get_json_params() { return json_decode( $this->body, true ); }
	function get_param( $k ) { $j = json_decode( $this->body, true ); return $j[ $k ] ?? null; }
}
class WC_Product {
	public $d;
	function __construct( $d ) { $this->d = $d + array( 'id'=>1,'type'=>'simple','status'=>'publish','name'=>'Product','description'=>'','short_description'=>'','price'=>'10','regular_price'=>'10','sale'=>false,'sku'=>'','parent_id'=>0,'image_id'=>0,'gallery'=>array(),'cats'=>array(),'attrs'=>array(),'meta'=>array(),'stock'=>null,'in_stock'=>true,'backorder'=>false,'weight'=>'','visibility'=>'visible','total_sales'=>0,'created'=>0,'virtual'=>false ); }
	function get_id() { return $this->d['id']; } function get_status() { return $this->d['status']; } function get_name() { return $this->d['name']; }
	function is_type( $t ) { return in_array( $this->d['type'], (array) $t, true ); }
	function get_parent_id() { return $this->d['parent_id']; } function get_catalog_visibility() { return $this->d['visibility']; }
	function variation_is_visible() { return true; } function is_in_stock() { return $this->d['in_stock']; } function is_on_backorder( $q = 1 ) { return $this->d['backorder']; }
	function is_on_sale() { return $this->d['sale']; } function is_virtual() { return $this->d['virtual']; }
	function get_category_ids() { return $this->d['cats']; } function get_description() { return $this->d['description']; } function get_short_description() { return $this->d['short_description']; }
	function get_permalink() { return 'http://shop.test/product/' . $this->d['id'] . '/'; } function get_image_id() { return $this->d['image_id']; } function get_gallery_image_ids() { return $this->d['gallery']; }
	function get_meta( $k, $single = true ) { return $this->d['meta'][ $k ] ?? ''; } function get_attribute( $k ) { return $this->d['attrs'][ $k ] ?? ''; }
	function get_weight() { return $this->d['weight']; } function get_price() { return $this->d['price']; } function get_regular_price() { return $this->d['regular_price']; }
	function get_date_on_sale_from() { return null; } function get_date_on_sale_to() { return null; } function get_sku() { return $this->d['sku']; }
	function managing_stock() { return null !== $this->d['stock']; } function get_stock_quantity() { return $this->d['stock']; }
	function get_total_sales() { return $this->d['total_sales']; }
	function get_date_created() { return $this->d['created'] ? new DateTime( '@' . $this->d['created'] ) : null; }
}

function get_option( $k, $d = false ) { return $GLOBALS['t']['options'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['t']['options'][ $k ] = $v; return true; }
function add_option( $k, $v ) { if ( isset( $GLOBALS['t']['options'][ $k ] ) ) return false; $GLOBALS['t']['options'][ $k ] = $v; return true; }
function get_transient( $k ) { return $GLOBALS['t']['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['t']['transients'][ $k ] = $v; $GLOBALS['t']['transient_writes'] = ( $GLOBALS['t']['transient_writes'] ?? 0 ) + 1; return true; }
function delete_transient( $k ) { unset( $GLOBALS['t']['transients'][ $k ] ); }
function apply_filters( $tag, $v ) { return isset( $GLOBALS['t']['filters'][ $tag ] ) ? $GLOBALS['t']['filters'][ $tag ]( $v ) : $v; }
function add_action() {} function add_filter() {}
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( (string) $u, $c ); }
function home_url( $p = '' ) { return 'http://shop.test' . $p; }
function rest_url( $p = '' ) { return 'http://shop.test/wp-json/' . $p; }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ); }
function esc_url_raw( $v ) { return (string) $v; } function esc_html( $v ) { return htmlspecialchars( (string) $v ); } function esc_attr( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES ); }
function esc_js( $v ) { return addslashes( (string) $v ); }
function wp_unslash( $v ) { return $v; } function absint( $v ) { return abs( (int) $v ); }
function is_email( $v ) { return (bool) filter_var( $v, FILTER_VALIDATE_EMAIL ); }
function wp_strip_all_tags( $v ) { return trim( strip_tags( (string) $v ) ); } function strip_shortcodes( $v ) { return $v; } function wp_check_invalid_utf8( $v ) { return $v; }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function is_user_logged_in() { return $GLOBALS['t']['user'] > 0; } function get_current_user_id() { return $GLOBALS['t']['user']; }
function wp_get_current_user() { $u = new stdClass; $u->roles = $GLOBALS['t']['user'] ? array( 'customer' ) : array(); $u->user_email = 'jane@example.com'; $u->first_name = 'Jane'; $u->last_name = 'Doe'; $u->exists = null; return new class( $u ) { public $roles, $user_email, $first_name, $last_name; function __construct( $u ) { foreach ( get_object_vars( $u ) as $k => $v ) $this->$k = $v; } function exists() { return $GLOBALS['t']['user'] > 0; } }; }
function get_user_meta() { return ''; } function current_user_can() { return false; }
function is_admin() { return false; } function wp_doing_ajax() { return false; } function wp_doing_cron() { return false; }
function wp_generate_uuid4() { return sprintf( '%08x-%04x-4%03x-a%03x-%012x', mt_rand(), mt_rand( 0, 0xffff ), mt_rand( 0, 0xfff ), mt_rand( 0, 0xfff ), mt_rand() ); }
function wp_remote_post( $url, $args ) { $GLOBALS['t']['http'][] = array( 'url' => $url, 'body' => json_decode( $args['body'], true ), 'headers' => $args['headers'] ); return array( 'code' => 200, 'body' => json_encode( array( 'events_received' => count( json_decode( $args['body'], true )['data'] ?? array( 1 ) ) ) ) ); }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; } function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_get_document_title() { return 'Clip-in extensions – Shop'; }
function is_singular() { return false; } function get_post_type() { return ''; } function get_the_ID() { return 0; } function is_search() { return false; }
function is_ssl() { return false; } function wp_create_nonce() { return 'nonce123'; } function post_password_required() { return false; }
function taxonomy_exists() { return false; }
function get_ancestors( $id ) { $out = array(); while ( ! empty( $GLOBALS['t']['terms'][ $id ]['parent'] ) ) { $id = $GLOBALS['t']['terms'][ $id ]['parent']; $out[] = $id; } return $out; }
function get_term( $id ) { return isset( $GLOBALS['t']['terms'][ $id ] ) ? (object) $GLOBALS['t']['terms'][ $id ] : null; }
function add_query_arg( $args, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . implode( '&', array_map( function ( $k, $v ) { return $k . '=' . $v; }, array_keys( $args ), $args ) ); }
function wp_get_attachment_url( $id ) { return $id ? 'http://shop.test/wp-content/uploads/img-' . $id . '.jpg' : false; }
function wc_get_product( $id ) { return $GLOBALS['t']['products'][ $id ] ?? null; }
function wc_get_product_terms( $id, $tax, $args = array() ) { $p = wc_get_product( $id ); $out = array(); foreach ( $p ? $p->d['cats'] : array() as $c ) $out[] = $GLOBALS['t']['terms'][ $c ]['name']; return $out; }
function wc_get_price_to_display( $p, $a = array() ) { return (float) ( $a['price'] ?? $p->get_price() ); }
function wc_get_price_including_tax( $p, $a = array() ) { return (float) $a['price']; } function wc_get_price_excluding_tax( $p, $a = array() ) { return (float) $a['price']; }
function get_woocommerce_currency() { return 'BAM'; } function wc_get_weight( $w, $u ) { return $w; } function wc_get_low_stock_amount( $p ) { return 2; }
function wc_get_product_id_by_sku( $s ) { return 0; } function wc_get_formatted_variation( $p ) { return implode( ', ', $p->d['attrs'] ); }

require dirname( __DIR__ ) . '/includes/class-dxps-helpers.php';
require dirname( __DIR__ ) . '/includes/class-dxps-jobs.php';
require dirname( __DIR__ ) . '/includes/class-dxps-server.php';
require dirname( __DIR__ ) . '/includes/class-dxps-tracking.php';
require dirname( __DIR__ ) . '/includes/class-dxps-feed-product.php';
require dirname( __DIR__ ) . '/includes/class-dxps-feed-options.php';
require dirname( __DIR__ ) . '/includes/class-dxps-feeds.php';
