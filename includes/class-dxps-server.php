<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Server-side layer:
 *  - REST relay: browser events mirrored to Meta CAPI + GA4 MP (first-party,
 *    survives ad-blockers/ITP; deduped via shared event_id).
 *  - Status-based Purchase (COD-safe): fires Meta CAPI + GA4 MP when the order
 *    reaches the configured status.
 *  - Refund events.
 */
class DXPS_Server {

	const META_API_VERSION = 'v25.0'; // override with the dxps_meta_api_version filter
	const GA4_API  = 'https://www.google-analytics.com/mp/collect';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'wp_ajax_dxps_test_capi', array( __CLASS__, 'admin_test_capi' ) );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_change' ), 10, 4 );
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'on_refund' ), 10, 2 );
		if ( DXPS_Helpers::on( 'track_signup' ) )   add_action( 'user_register', array( __CLASS__, 'on_signup' ) );
		if ( DXPS_Helpers::on( 'track_login' ) )    add_action( 'wp_login', array( __CLASS__, 'on_login' ), 10, 2 );
		if ( DXPS_Helpers::on( 'track_comments' ) ) add_action( 'comment_post', array( __CLASS__, 'on_comment' ), 10, 2 );
		// ACR: Automatic Conversion Recovery — nightly sweep for missed purchases.
		add_action( 'dxps_cron_acr', array( __CLASS__, 'acr_run' ) );
		add_action( 'dxps_cron_acr_batch', array( __CLASS__, 'acr_run' ), 10, 1 );
		$next = wp_next_scheduled( 'dxps_cron_acr' );
		if ( DXPS_Helpers::on( 'acr_enabled' ) && ! $next ) {
			wp_schedule_event( strtotime( 'tomorrow 3am' ), 'daily', 'dxps_cron_acr' );
		} elseif ( ! DXPS_Helpers::on( 'acr_enabled' ) && $next ) {
			wp_clear_scheduled_hook( 'dxps_cron_acr' );
		}
	}

	private static function simple_user_event( $meta_name, $ga4_name, $user_id = 0, $extra = array() ) {
		// user_register / comment_post also fire when an administrator creates a user or replies in wp-admin.
		// Those are not visitor conversions and would carry the administrator's cookies, IP and consent.
		if ( is_admin() && ! wp_doing_ajax() ) return;
		if ( wp_doing_ajax() && 'Login' !== $meta_name && ( current_user_can( 'create_users' ) || current_user_can( 'moderate_comments' ) ) ) return;
		$user = $user_id ? array_filter( array(
			'em'          => DXPS_Helpers::hash( get_userdata( $user_id )->user_email ?? '' ),
			'external_id' => DXPS_Helpers::external_id(),
			'fbp'         => DXPS_Helpers::fbp(),
			'fbc'         => DXPS_Helpers::fbc(),
			'client_ip_address' => self::ip(),
			'client_user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ), 0, 500 ) : '',
		) ) : array_merge( DXPS_Helpers::user_data_full(), array_filter( array(
			'external_id' => DXPS_Helpers::external_id(), 'fbp' => DXPS_Helpers::fbp(), 'fbc' => DXPS_Helpers::fbc(),
			'client_ip_address' => self::ip(),
		) ) );
		$eid = DXPS_Helpers::event_id( $ga4_name );
		self::send_meta( $meta_name, $extra, $eid, $user );
		self::send_ga4( $ga4_name, $extra, DXPS_Helpers::ga_client_id(), array(), $user_id ? (int) $user_id : null );
	}

	public static function on_signup( $user_id )      { self::simple_user_event( 'CompleteRegistration', 'sign_up', $user_id ); }
	public static function on_login( $login, $user )  { self::simple_user_event( 'Login', 'login', $user->ID ); }
	public static function on_comment( $id, $approved ) { if ( 1 === (int) $approved ) self::simple_user_event( 'Comment', 'comment', 0, array( 'comment_id' => $id ) ); }

	/** ACR: nightly recovery of purchases the pixel/status hook missed. */
	public static function acr_run( $page = 1 ) {
		if ( ! DXPS_Helpers::on( 'acr_enabled' ) ) return;
		$status = DXPS_Helpers::opt( 'purchase_status', 'completed' );
		if ( ! function_exists( 'wc_get_orders' ) ) return;
		$orders = wc_get_orders( array(
			'status'       => 'wc-' . $status,
			'date_modified' => '>' . ( time() - 7 * DAY_IN_SECONDS ),
			'limit'        => 100,
			'page'         => max( 1, (int) $page ),
			'orderby'      => 'ID',
			'order'        => 'ASC',
		) );
		$n = 0;
		foreach ( $orders as $order ) {
			if ( $order->get_meta( '_dxps_purchase_sent' ) ) continue;
			if ( DXPS_Delivery::queue( $order->get_id() ) ) $n++;
		}
		if ( count( $orders ) === 100 ) DXPS_Jobs::schedule( 'dxps_cron_acr_batch', array( (int) $page + 1 ), 30 );
		DXPS_Helpers::log( 'ACR run', array( 'recovered' => $n ) );
	}

	public static function routes() {
		register_rest_route( 'dxps/v1', '/e', array(
			'methods'             => 'POST',
			'permission_callback' => '__return_true', // public visitors; nonce-checked below.
			'callback'            => array( __CLASS__, 'relay' ),
		) );
		// Customer data detected in on-page forms (Automatic Advanced Matching).
		register_rest_route( 'dxps/v1', '/forget', array( 'methods'=>'POST', 'permission_callback'=>'__return_true', 'callback'=>array( __CLASS__, 'forget' ) ) );
		register_rest_route( 'dxps/v1', '/u', array(
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => array( __CLASS__, 'ingest_user_data' ),
		) );
	}

	/** Same-origin + rate-limit guard shared by both public endpoints. */
	private static function guard( WP_REST_Request $req ) {
		if ( strlen( $req->get_body() ) > 65536 || DXPS_Helpers::excluded_role() ) return false;
		$origin = $req->get_header( 'origin' ) ?: $req->get_header( 'referer' );
		$host   = $origin ? wp_parse_url( $origin, PHP_URL_HOST ) : '';
		$site   = wp_parse_url( home_url(), PHP_URL_HOST );
		$source = wp_parse_url( $origin ); $target = wp_parse_url( home_url() );
		if ( ! $host || strtolower( $host ) !== strtolower( $site ) || ( $source['scheme'] ?? '' ) !== ( $target['scheme'] ?? '' ) || (int) ( $source['port'] ?? ( 'https' === ( $source['scheme'] ?? '' ) ? 443 : 80 ) ) !== (int) ( $target['port'] ?? ( 'https' === ( $target['scheme'] ?? '' ) ? 443 : 80 ) ) ) return false;
		if ( DXPS_Helpers::on( 'block_bots' ) && DXPS_Helpers::is_bot() ) return false;
		$ip   = self::ip();
		$key = 'dxps_rl_' . md5( $ip . '|' . floor( time() / 60 ) );
		$rate = (int) get_transient( $key );
		if ( $rate >= 120 ) return false;
		set_transient( $key, $rate + 1, 2 * MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Ingest customer data that the front-end detected in page forms.
	 *
	 * Values arrive as plain text over HTTPS and are hashed HERE, server-side, before being
	 * stored — nothing unhashed is ever persisted or forwarded. Stored against external_id
	 * in the 10-minute transient, so every subsequent event (browser Advanced Matching and
	 * server CAPI alike) is enriched with it. This is what lifts Meta's "percent of events
	 * sending" coverage for phone / first name / last name / city / zip.
	 */
	public static function ingest_user_data( WP_REST_Request $req ) {
		if ( ! self::guard( $req ) ) return new WP_REST_Response( array( 'ok' => false ), 403 );

		if ( ! DXPS_Helpers::marketing_allowed() ) return new WP_REST_Response( array( 'ok' => true, 'skipped' => 'consent' ), 200 );

		if ( ! DXPS_Helpers::on( 'meta_enabled' ) && ! DXPS_Helpers::on( 'ga4_enabled' ) ) return new WP_REST_Response( array( 'ok'=>true, 'skipped'=>'disabled' ), 200 );
		if ( ! DXPS_Helpers::on( 'auto_am' ) ) return new WP_REST_Response( array( 'ok'=>true,'skipped'=>'matching_disabled' ) );
		$in  = (array) $req->get_json_params();
		$raw = (array) ( $in['user'] ?? array() );
        if ( ( DXPS_Helpers::sensitive_context( $in['url'] ?? '' ) || DXPS_Helpers::sensitive_context( $req->get_header( 'referer' ) ) ) ) return new WP_REST_Response( array( 'ok'=>true, 'skipped'=>'sensitive_context' ), 200 );
		$hashed = ! empty( $in['hashed'] ) ? DXPS_Helpers::valid_user_data( array_intersect_key( $raw, array_flip( array( 'em','ph','fn','ln','ct','st','zp','country' ) ) ) ) : DXPS_Helpers::matching_hashes( $raw );
		if ( ! $hashed ) return new WP_REST_Response( array( 'ok' => true, 'stored' => 0 ) );

		// Merge with whatever is already remembered so partial form fills accumulate.
		$previous = DXPS_Helpers::recalled_user_data();
		if ( ! empty( $previous['em'] ) && ! empty( $hashed['em'] ) && $previous['em'] !== $hashed['em'] ) $previous = array();
		$merged = array_merge( $previous, $hashed );
		DXPS_Helpers::remember_user_data( $merged );
		DXPS_Helpers::log( 'Form user data ingested', array( 'fields' => array_keys( $hashed ) ) );
		return new WP_REST_Response( array( 'ok' => true, 'stored' => count( $hashed ) ) );
	}

	/* ============================ RELAY ============================ */

	public static function relay( WP_REST_Request $req ) {
		if ( ! self::guard( $req ) ) return new WP_REST_Response( array( 'ok'=>false ), 403 );
		if ( ! DXPS_Helpers::on( 'server_relay' ) || ! DXPS_Helpers::marketing_allowed() ) return new WP_REST_Response( array( 'ok'=>true,'skipped'=>'disabled_or_consent' ) );

		// 2.2.0: the browser sends its events in small batches ({events:[...]}); one request, one Conversions API call.
		// A single event object (the pre-2.2 shape, still used by cached pages) is accepted as a batch of one.
		$body = $req->get_json_params();
		if ( ! is_array( $body ) ) $body = array( 'name'=>$req->get_param( 'name' ), 'event_id'=>$req->get_param( 'event_id' ), 'url'=>$req->get_param( 'url' ), 'params'=>$req->get_param( 'params' ) );
		$list = array_key_exists( 'events', $body ) ? $body['events'] : array( $body );
		if ( ! is_array( $list ) || ! $list || count( $list ) > 20 ) return new WP_REST_Response( array( 'ok'=>false ), 400 );

		$meta_ready = DXPS_Helpers::on( 'meta_enabled' ) && DXPS_Helpers::opt( 'meta_pixel_id' ) && DXPS_Helpers::opt( 'meta_capi_token' );
		$allowed    = array_merge( array( 'page_view', 'view_item', 'view_item_list', 'add_to_cart', 'remove_from_cart', 'view_cart', 'begin_checkout', 'add_to_wishlist', 'add_payment_info', 'add_shipping_info', 'search', 'generate_lead', 'scroll', 'time_on_page', 'internal_click', 'form_submit', 'file_download', 'outbound_click', 'view_content', 'view_content_list', 'content_engaged' ), self::custom_event_names() );
		// Purchases and account events are sent by the server itself from authoritative data, never relayed.
		$server_owned = array( 'purchase', 'login', 'sign_up', 'comment' );

		$events = array(); $names = array(); $skipped = array(); $user = null; $matching = array();
		foreach ( $list as $e ) {
			if ( ! is_array( $e ) || ! is_string( $e['name'] ?? null ) || ! is_string( $e['event_id'] ?? null ) || ! is_string( $e['url'] ?? null ) || ! is_array( $e['params'] ?? null ) ) return new WP_REST_Response( array( 'ok'=>false ), 400 );
			$name = sanitize_text_field( $e['name'] ); $event_id = sanitize_text_field( $e['event_id'] );
			if ( in_array( $name, $server_owned, true ) ) { $skipped[] = 'server_owned'; continue; }
			if ( ! in_array( $name, $allowed, true ) || ! preg_match( '/^[A-Za-z0-9_.:-]{1,100}$/', $event_id ) ) return new WP_REST_Response( array( 'ok'=>false ), 400 );
			if ( DXPS_Helpers::sensitive_context( $e['url'], $e['params'] ) || DXPS_Helpers::sensitive_context( $name ) ) { $skipped[] = 'sensitive_context'; continue; }
			$params = self::relay_params( $e['params'] );
			if ( is_wp_error( $params ) ) return new WP_REST_Response( array( 'ok'=>false,'error'=>'invalid_parameters' ), 400 );
			if ( ! $meta_ready ) { $skipped[] = 'disabled'; continue; }
			if ( null === $user ) {
				$matching = DXPS_Helpers::user_data_full();
				// Geo fill-in (opt-in) goes first, so anything the customer actually gave overrides it.
				$user = array_merge( DXPS_Helpers::geo_hashes(), $matching, array_filter( array(
					'client_ip_address' => self::ip(),
					'client_user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ), 0, 500 ) : '',
					'fbp'               => DXPS_Helpers::fbp(),
					'fbc'               => DXPS_Helpers::fbc(),
					'external_id'       => DXPS_Helpers::external_ids(),
				) ) );
			}
			$meta_name = self::meta_event_name( $name );
			$built     = self::meta_event( $meta_name, $params, $event_id, $user, DXPS_Helpers::safe_event_url( esc_url_raw( $e['url'] ) ) );
			if ( empty( $built['event'] ) ) { $skipped[] = $built['status']; continue; }
			$events[] = $built['event']; $names[] = $meta_name;
		}

		// Meta deduplicates on event_name + event_id, so a repeated relay of the same event needs no local lock or marker.
		$meta = $events ? self::meta_post( $events ) : array( 'ok'=>false, 'status'=>$skipped ? $skipped[0] : 'empty' );
		if ( $events ) {
			DXPS_Helpers::log( 'CAPI relay', $meta + array( 'events'=>$names, 'user_data_fields'=>array_keys( $user ) ) );
			foreach ( $events as $i => $ev ) DXPS_Helpers::log_event( array( 'e'=>$names[ $i ], 'ok'=>$meta['ok'] ? 1 : 0, 's'=>$meta['status'], 'f'=>array_keys( $ev['user_data'] ), 'v'=>isset( $ev['custom_data']['value'] ) ? 1 : 0 ) );
		}
		$out = array( 'ok'=>! empty( $meta['ok'] ), 'sent'=>! empty( $meta['ok'] ) ? count( $events ) : 0, 'skipped'=>array_values( array_unique( $skipped ) ), 'delivery'=>array( 'meta'=>array_intersect_key( $meta, array_flip( array( 'ok','status','http_status','events_received','error_code' ) ) ) ) );
		// Hand the visitor's own (already hashed) matching fields back to the pixel, so browser events carry the same
		// customer information as the server copies. Never placed in cacheable HTML; only in this private POST response.
		if ( DXPS_Helpers::on( 'auto_am' ) && $matching ) {
			$am = array_intersect_key( $matching, array_flip( array( 'em','ph','fn','ln','ct','st','zp','country' ) ) );
			if ( $am ) $out['am'] = $am;
		}
		$res = new WP_REST_Response( $out, 200 );
		$res->header( 'Cache-Control', 'no-store, private' );
		return $res;
	}

	private static function relay_params( $input ) {
		$out = array();
		foreach ( array( 'value','percent_scrolled','time_on_page' ) as $key ) {
			if ( ! isset( $input[$key] ) ) continue;
			if ( ! is_numeric( $input[$key] ) || ! is_finite( (float) $input[$key] ) || $input[$key] < 0 ) return new WP_Error( 'params' );
			$out[$key] = (float) $input[$key];
		}
		foreach ( array( 'currency','content_id','content_type','lead_type','transaction_id' ) as $key ) {
			if ( ! isset( $input[$key] ) ) continue;
			if ( ! is_scalar( $input[$key] ) ) return new WP_Error( 'params' );
			$out[$key] = substr( sanitize_text_field( (string) $input[$key] ), 0, 100 );
		}
		if ( isset( $out['currency'] ) && ! preg_match( '/^[A-Z]{3}$/', $out['currency'] ) ) return new WP_Error( 'params' );
		if ( isset( $input['items'] ) ) {
			if ( ! is_array( $input['items'] ) || count( $input['items'] ) > 100 ) return new WP_Error( 'params' );
			$out['items'] = array();
			foreach ( $input['items'] as $item ) {
				if ( ! is_array( $item ) || ! isset( $item['id'] ) || ! is_scalar( $item['id'] ) ) return new WP_Error( 'params' );
				$i = array( 'id'=>substr( sanitize_text_field( (string) $item['id'] ), 0, 100 ) );
				foreach ( array( 'quantity','price' ) as $key ) if ( isset( $item[$key] ) ) {
					if ( ! is_numeric( $item[$key] ) || ! is_finite( (float) $item[$key] ) || $item[$key] < 0 ) return new WP_Error( 'params' );
					$i[$key] = (float) $item[$key];
				}
				$out['items'][] = $i;
			}
		}
		return $out;
	}

	private static function ip() { return DXPS_Helpers::client_ip(); }

	public static function forget( WP_REST_Request $req ) {
		if ( ! self::guard( $req ) ) return new WP_REST_Response( array( 'ok'=>false ), 403 );
		$eid = DXPS_Helpers::external_id();
		if ( $eid ) delete_transient( 'dxps_eid_' . substr( $eid, 0, 32 ) );
		foreach ( array( 'pbid','_dxps_eid' ) as $cookie ) if ( isset( $_COOKIE[$cookie] ) && is_string( $_COOKIE[$cookie] ) && preg_match( '/^[a-f0-9]{32,64}$/i', $_COOKIE[$cookie] ) ) delete_transient( 'dxps_eid_' . substr( strtolower( $_COOKIE[$cookie] ), 0, 32 ) );
		return new WP_REST_Response( array( 'ok'=>true ), 200 );
	}

	private static function meta_event_name( $ga4_name ) {
		$map = array(
			'page_view'        => 'PageView',
			'view_item'        => 'ViewContent',
			'view_item_list'   => 'ViewCategory',
			'add_to_cart'      => 'AddToCart',
			'remove_from_cart' => 'RemoveFromCart',
			'view_cart'        => 'ViewCart',
			'begin_checkout'   => 'InitiateCheckout',
			'add_to_wishlist' => 'AddToWishlist',
			'add_payment_info' => 'AddPaymentInfo',
			'add_shipping_info' => 'AddShippingInfo',
			'search'           => 'Search',
			'generate_lead'    => 'Lead',
			'purchase'         => 'Purchase',
			'scroll'           => 'PageScroll',
			'time_on_page'     => 'TimeOnPage',
			'internal_click'   => 'InternalClick',
			'form_submit'      => 'Form',
			'file_download'    => 'Download',
			'login'            => 'Login',
			'sign_up'          => 'CompleteRegistration',
			'comment'          => 'Comment',
			'outbound_click'   => 'OutboundClick',
		);
		if ( isset( $map[ $ga4_name ] ) ) return $map[ $ga4_name ];
		$content = DXPS_Helpers::content_meta_names();
		if ( isset( $content[ $ga4_name ] ) ) return $content[ $ga4_name ];
		// Custom rules named after a Meta standard event (Lead, Schedule, Contact ...) keep Meta's exact casing,
		// which the browser pixel uses too - deduplication needs identical names on both sides.
		return DXPS_Helpers::META_STANDARD[ $ga4_name ] ?? $ga4_name;
	}

	/** Names of the custom events configured in settings. */
	public static function custom_event_names() {
		return array_values( array_unique( array_column( DXPS_Helpers::custom_events(), 'name' ) ) );
	}

	/* ============================ META CAPI ============================ */

	/** Test-only diagnostic: authenticated administrators, CSRF checked, no setting changes. */
	public static function admin_test_capi() {
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'status' => 'forbidden' ), 403 );
		check_ajax_referer( 'dxps_settings', 'nonce' );
		$code = sanitize_text_field( wp_unslash( $_POST['test_event_code'] ?? '' ) );
		if ( ! preg_match( '/^TEST[0-9]{1,30}$/', $code ) ) wp_send_json_error( array( 'status' => 'invalid_test_code' ), 400 );
		$key = 'dxps_test_capi_' . get_current_user_id();
		if ( get_transient( $key ) ) wp_send_json_error( array( 'status' => 'rate_limited' ), 429 );
		set_transient( $key, 1, 30 );
		$id = 'dxps.test.' . wp_generate_uuid4();
		$user = array_filter( array(
			'external_id' => hash( 'sha256', $id ),
			'client_ip_address' => self::ip(),
			'client_user_agent' => substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 0, 500 ),
		) );
		$result = self::send_meta( 'PageView', array( '_dxps_marketing_allowed' => true ), $id, $user, home_url( '/' ), $code );
		$result['event_id'] = $id;
		$result['test_only'] = true;
		wp_send_json_success( $result );
	}

	public static function send_meta( $event_name, $params, $event_id, $user_data, $url = '', $test_code = '' ) {
		$pixel = DXPS_Helpers::opt( 'meta_pixel_id' );
		$token = DXPS_Helpers::opt( 'meta_capi_token' );
		// A valid test code is permitted only in an authenticated administrator AJAX request.
		$diagnostic = $test_code && preg_match( '/^TEST[0-9]{1,30}$/', $test_code ) && wp_doing_ajax() && current_user_can( 'manage_options' );
		if ( $test_code && ! $diagnostic ) return array( 'ok' => false, 'status' => 'forbidden_test' );
		// Order events can be retried by several workers; remember an accepted one. Page events rely on Meta's own
		// event_id deduplication instead of two database rows per event.
		$marker = ( ! $diagnostic && 'Purchase' === $event_name ) ? 'dxps_sent_' . md5( $pixel . '|' . $event_name . '|' . $event_id ) : '';
		if ( $marker && get_transient( $marker ) ) return array( 'ok'=>true,'status'=>'already_accepted' );
		if ( ! $diagnostic && ! DXPS_Helpers::on( 'meta_enabled' ) ) return array( 'ok' => false, 'status' => 'disabled' );
		if ( ! $pixel || ! $token ) return array( 'ok' => false, 'status' => 'not_configured' );

		$built = self::meta_event( $event_name, $params, $event_id, $user_data, $url, $diagnostic );
		if ( empty( $built['event'] ) ) return $built;
		$result = self::meta_post( array( $built['event'] ), $diagnostic ? $test_code : '' );
		if ( $marker && ! empty( $result['ok'] ) ) set_transient( $marker, 1, 2 * DAY_IN_SECONDS );
		$user_data = $built['event']['user_data']; $custom = $built['event']['custom_data'];
		// Never log the access-token URL, raw API body, or customer data.
		DXPS_Helpers::log( 'CAPI ' . $event_name, $result + array( 'user_data_fields'=>array_keys( $user_data ), 'custom_data_fields'=>array_keys( $custom ) ) );
		if ( ! $diagnostic ) DXPS_Helpers::log_event( array( 'e' => $event_name, 'ok' => $result['ok'] ? 1 : 0, 's' => $result['status'], 'f' => array_keys( $user_data ), 'v' => isset( $custom['value'] ) ? 1 : 0 ) );
		return $result;
	}

	/**
	 * Validate one event and build its Conversions API payload.
	 * Returns array( 'event' => payload ) or array( 'ok' => false, 'status' => reason ).
	 */
	private static function meta_event( $event_name, $params, $event_id, $user_data, $url = '', $diagnostic = false ) {
		$allowed = array_key_exists( '_dxps_marketing_allowed', $params ) ? (bool) $params['_dxps_marketing_allowed'] : DXPS_Helpers::marketing_allowed();
		if ( ! $allowed ) return array( 'ok' => false, 'status' => 'consent' );

		if ( ! $diagnostic && ( DXPS_Helpers::sensitive_context( $url, $params ) || DXPS_Helpers::sensitive_context( $event_name ) ) ) return array( 'ok'=>false, 'status'=>'sensitive_context' );
		if ( ! empty( $params['_dxps_event_time'] ) && (int) $params['_dxps_event_time'] < time() - 7 * DAY_IN_SECONDS ) return array( 'ok'=>false,'status'=>'event_expired' );
		$user_data = DXPS_Helpers::valid_user_data( $user_data );
		if ( ! $user_data ) return array( 'ok'=>false, 'status'=>'insufficient_user_data' );
		if ( ! preg_match( '/^[A-Za-z0-9_.:-]{1,100}$/', $event_id ) ) return array( 'ok'=>false, 'status'=>'invalid_event_id' );
		$url = DXPS_Helpers::safe_event_url( $url ?: home_url( '/' ) );
		$custom = array();
		if ( isset( $params['value'] ) && is_numeric( $params['value'] ) && is_finite( (float) $params['value'] ) && (float) $params['value'] >= 0 ) $custom['value'] = (float) $params['value'];
		if ( isset( $custom['value'] ) || ! empty( $params['items'] ) ) {
			$cur = strtoupper( $params['currency'] ?? ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'BAM' ) );
			if ( preg_match( '/^[A-Z]{3}$/', $cur ) ) $custom['currency'] = $cur;
		}
		$contents = array();
		foreach ( array_slice( (array) ( $params['items'] ?? array() ), 0, 100 ) as $i ) {
			if ( ! is_array( $i ) || empty( $i['id'] ) || ! is_scalar( $i['id'] ) ) continue;
			$item = array( 'id'=>substr( sanitize_text_field( (string) $i['id'] ), 0, 100 ), 'quantity'=>max( 1, min( 9999, (int) ( $i['quantity'] ?? 1 ) ) ) );
			if ( isset( $i['price'] ) && is_numeric( $i['price'] ) && is_finite( (float) $i['price'] ) && $i['price'] >= 0 ) $item['item_price'] = (float) $i['price'];
			$contents[] = $item;
		}
		if ( $contents ) { $custom['content_type']= ( $params['content_type'] ?? '' ) === 'product_group' ? 'product_group' : 'product'; $custom['contents']=$contents; $custom['content_ids']=array_column( $contents, 'id' ); $custom['num_items']=array_sum( array_column( $contents, 'quantity' ) ); }
		// Content events (blog posts, pages, services): the post ID and a short type key, never titles or text.
		if ( ! $contents && isset( $params['content_id'] ) && is_scalar( $params['content_id'] ) && preg_match( '/^[0-9]{1,12}$/', (string) $params['content_id'] ) ) {
			$custom['content_ids'] = array( (string) $params['content_id'] );
		}
		if ( ! $contents && ! empty( $params['content_type'] ) && is_string( $params['content_type'] ) && preg_match( '/^[a-z0-9_\-]{1,40}$/', $params['content_type'] ) ) {
			$custom['content_type'] = $params['content_type'];
		}
		if ( 'Purchase' === $event_name && ! empty( $params['transaction_id'] ) ) $custom['order_id'] = substr( sanitize_text_field( (string) $params['transaction_id'] ), 0, 100 );
		// Deliberately no titles, query strings, search text, form text, health coupons or inferred demographics.
		foreach ( array( 'percent_scrolled','time_on_page' ) as $k ) if ( isset( $params[ $k ] ) && is_numeric( $params[ $k ] ) && $params[ $k ] >= 0 ) $custom[ $k ] = min( 86400, (float) $params[ $k ] );
		if ( ! empty( $params['lead_type'] ) && in_array( $params['lead_type'], array( 'phone','email','whatsapp','booking','form' ), true ) ) $custom['lead_type'] = $params['lead_type'];

		$event = array(
			'event_name'       => $event_name,
			'event_time'       => ! empty( $params['_dxps_event_time'] ) ? (int) $params['_dxps_event_time'] : time(),
			'event_id'         => $event_id,
			'action_source'    => 'website',
			'event_source_url' => $url,
			'user_data'        => $user_data,
			'custom_data'      => $custom,
		);

		if ( DXPS_Helpers::on( 'meta_ldu' ) ) {
			// Limited Data Use: Meta geolocates the event and applies the US-state rules where they apply.
			$event['data_processing_options']         = array( 'LDU' );
			$event['data_processing_options_country'] = 0;
			$event['data_processing_options_state']   = 0;
		}
		return array( 'event' => $event );
	}

	/** One Conversions API request for one or more prepared events. */
	private static function meta_post( $events, $test_code = '' ) {
		$body = array( 'data' => array_values( $events ) );
		if ( $test_code ) $body['test_event_code'] = $test_code;
		elseif ( DXPS_Helpers::opt( 'meta_test_code' ) ) $body['test_event_code'] = DXPS_Helpers::opt( 'meta_test_code' );

		$version = preg_replace( '/[^v0-9.]/', '', (string) apply_filters( 'dxps_meta_api_version', self::META_API_VERSION ) );
		$res = wp_remote_post( 'https://graph.facebook.com/' . ( $version ?: self::META_API_VERSION ) . '/' . rawurlencode( DXPS_Helpers::opt( 'meta_pixel_id' ) ) . '/events', array(
			'timeout' => 5,
			'headers' => array( 'Content-Type' => 'application/json', 'Authorization'=>'Bearer ' . DXPS_Helpers::opt( 'meta_capi_token' ) ),
			'body'    => wp_json_encode( $body ),
		) );
		if ( is_wp_error( $res ) ) {
			$result = array( 'ok' => false, 'status' => 'transport_error' );
		} else {
			$http = (int) wp_remote_retrieve_response_code( $res );
			$json = json_decode( wp_remote_retrieve_body( $res ), true );
			$accepted = $http >= 200 && $http < 300 && is_array( $json ) && empty( $json['error'] ) && (int) ( $json['events_received'] ?? 0 ) > 0;
			$result = array( 'ok' => (bool) $accepted, 'status' => $accepted ? 'accepted' : 'rejected', 'http_status' => $http, 'events_received' => is_array( $json ) ? (int) ( $json['events_received'] ?? 0 ) : 0 );
			if ( ! empty( $json['error']['code'] ) ) $result['error_code'] = (int) $json['error']['code'];
		}
		$result['retryable'] = ! $result['ok'] && ( empty( $result['http_status'] ) || 429 === $result['http_status'] || $result['http_status'] >= 500 );
		return $result;
	}

	/* ============================ GA4 MP ============================ */

	/**
	 * @param int|null $user_id GA4 user_id. null = the current visitor, but only on front-end requests. Order-based
	 *                          sends (status hook, ACR, refunds) must pass the order's customer ID explicitly: they run
	 *                          in the request of the administrator who changed the order, or in cron.
	 */
	public static function send_ga4( $event_name, $params, $client_id, $user_data_ga = array(), $user_id = null ) {
		$mid    = DXPS_Helpers::opt( 'ga4_id' );
		$secret = DXPS_Helpers::opt( 'ga4_api_secret' );
		if ( ! DXPS_Helpers::on( 'ga4_enabled' ) ) return array( 'ok' => false, 'status' => 'disabled' );
		if ( ! DXPS_Helpers::ga4_server_allowed( $event_name ) ) return array( 'ok' => false, 'status' => 'direct_google_mode' );
		if ( ! $mid || ! $secret ) return array( 'ok' => false, 'status' => 'not_configured' );
		$allowed = array_key_exists( '_dxps_marketing_allowed', $params ) ? (bool) $params['_dxps_marketing_allowed'] : DXPS_Helpers::marketing_allowed();
		if ( ! $allowed ) return array( 'ok' => false, 'status' => 'consent' );

		if ( ! empty( $params['_dxps_event_time'] ) && (int) $params['_dxps_event_time'] < time() - 3 * DAY_IN_SECONDS ) return array( 'ok'=>false,'status'=>'event_expired' );
		if ( DXPS_Helpers::sensitive_context( '', $params ) ) return array( 'ok'=>false,'status'=>'sensitive_context' );
		$p = array();
		if ( isset( $params['value'] ) )          $p['value']    = (float) $params['value'];
		$p['currency'] = isset( $params['currency'] ) ? $params['currency'] : ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'BAM' );
		if ( ! empty( $params['transaction_id'] ) ) $p['transaction_id'] = $params['transaction_id'];
		if ( ! empty( $params['coupon'] ) )         $p['coupon']         = $params['coupon'];

		if ( ! empty( $params['item_list_name'] ) ) $p['item_list_name'] = $params['item_list_name'];
		foreach ( array( 'post_type','post_id','percent_scrolled','time_on_page','lead_type','content_type','content_id','num_items','tax','shipping','discount','new_customer','payment_method','session_id','engagement_time_msec' ) as $pk ) {
			if ( isset( $params[ $pk ] ) && '' !== $params[ $pk ] && is_scalar( $params[ $pk ] ) ) $p[ $pk ] = $params[ $pk ];
		}
		if ( ! empty( $params['items'] ) ) {
			$p['items'] = array_map( function ( $i ) {
				return array_filter( array(
					'item_id'       => $i['id'],
					'item_name'     => isset( $i['name'] ) ? $i['name'] : '',
					'price'         => isset( $i['price'] ) ? $i['price'] : 0,
					'quantity'      => isset( $i['quantity'] ) ? $i['quantity'] : 1,
					'item_category' => isset( $i['category'] ) ? $i['category'] : '',
					'item_brand'    => isset( $i['brand'] ) ? $i['brand'] : '',
					'item_variant'  => isset( $i['variant'] ) ? $i['variant'] : '',
				) );
			}, $params['items'] );
		}

		$body = array(
			'client_id' => $client_id ?: ( rand( 100000000, 999999999 ) . '.' . time() ),
			'events'    => array( array( 'name' => $event_name, 'params' => $p ) ),
		);
		if ( null === $user_id ) $user_id = ( ! is_admin() && ! wp_doing_cron() && is_user_logged_in() ) ? get_current_user_id() : 0;
		if ( $user_id > 0 ) $body['user_id'] = 'u' . (int) $user_id;
		if ( ! empty( $params['_dxps_event_time'] ) ) $body['timestamp_micros'] = (int) $params['_dxps_event_time'] * 1000000;
		// Without an IP the Measurement Protocol reports no country/city for server events. Order events use the
		// address saved at checkout; front-end requests use the visitor's own. Never the administrator's or cron's.
		$geo_ip = ! empty( $params['_dxps_ip'] ) ? $params['_dxps_ip'] : ( ( ! is_admin() && ! wp_doing_cron() && empty( $params['_dxps_event_time'] ) ) ? self::ip() : '' );
		if ( is_string( $geo_ip ) && filter_var( $geo_ip, FILTER_VALIDATE_IP ) ) $body['ip_override'] = $geo_ip;
		$body['consent'] = array( 'ad_user_data'=>'GRANTED', 'ad_personalization'=>'GRANTED' );
		if ( ! empty( $user_data_ga['address'] ) ) $user_data_ga['address'] = array( $user_data_ga['address'] );
		if ( $user_data_ga )       $body['user_data'] = $user_data_ga; // GA4 user-provided data.

		$res = wp_remote_post( self::GA4_API . '?measurement_id=' . rawurlencode( $mid ) . '&api_secret=' . rawurlencode( $secret ), array(
			'timeout' => 5,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $body ),
		) );
		$http = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		// GA4 2xx is transport acknowledgement, not proof of report ingestion.
		$result = array( 'ok' => $http >= 200 && $http < 300, 'status' => $http >= 200 && $http < 300 ? 'transport_accepted' : 'failed', 'http_status' => $http );
		$result['retryable'] = ! $result['ok'] && ( ! $http || 429 === $http || $http >= 500 );
		DXPS_Helpers::log( 'GA4 MP ' . $event_name, $result );
		return $result;
	}

	/* ============================ PURCHASE (STATUS MODE) ============================ */

	public static function on_status_change( $order_id, $from, $to, $order ) {
		if ( 'status' !== DXPS_Helpers::opt( 'purchase_trigger', 'status' ) ) return;
		if ( $to !== DXPS_Helpers::opt( 'purchase_status', 'completed' ) ) return;
		if ( $order->get_meta( '_dxps_purchase_sent' ) ) return;
		DXPS_Delivery::queue( $order->get_id() );
	}

	/** Shared server-side Purchase (status hook + ACR). */
	public static function send_purchase( $order ) {
		// Preserve legacy markers: never automatically replay previously flagged orders.
		if ( $order->get_meta( '_dxps_purchase_sent' ) ) return false;
		$need_meta = DXPS_Helpers::on( 'meta_enabled' ) && ! $order->get_meta( '_dxps_meta_purchase_sent' );
		$need_ga4 = DXPS_Helpers::on( 'ga4_enabled' ) && DXPS_Helpers::ga4_server_allowed( 'purchase' ) && ! $order->get_meta( '_dxps_ga4_purchase_sent' );
		if ( ! $need_meta && ! $need_ga4 ) return false;
		$consent = $order->get_meta( '_dxps_marketing_allowed' );
		// Legacy orders have no customer's checkout consent snapshot. Hold them for review.
		if ( '1' !== (string) $consent ) return false;
		if ( DXPS_Helpers::sensitive_context( '', array( 'items'=>DXPS_Helpers::order_items( $order ), 'coupons'=>$order->get_coupon_codes() ) ) ) return false;
		$order_id = $order->get_id();
		$event_time = (int) $order->get_meta( '_dxps_purchase_event_time' );
		if ( ! $event_time ) {
			$event_time = time();
			$order->update_meta_data( '_dxps_purchase_event_time', $event_time );
			$order->save();
		}

		$event_id = 'purchase.' . $order_id; // matches the browser Google-Ads-scope event id.
		$params   = array_merge( array(
			'transaction_id' => (string) $order->get_order_number(),
			'value'          => DXPS_Helpers::order_value( $order ),
			'currency'       => $order->get_currency(),
			'items'          => DXPS_Helpers::order_items( $order ),
			'coupon'         => implode( ',', DXPS_Helpers::safe_coupons( $order ) ),
			'new_customer'   => self::is_new_customer( $order ),
		), DXPS_Helpers::order_extras( $order ) );

		// Meta CAPI with the attribution snapshot saved at checkout.
		$from_order = DXPS_Helpers::user_data_from_order( $order );
		$user = array_merge( $from_order, array_filter( array(
			'client_ip_address' => $order->get_meta( '_dxps_ip' ),
			'client_user_agent' => $order->get_meta( '_dxps_ua' ),
			'fbp'               => $order->get_meta( '_dxps_fbp' ),
			'fbc'               => $order->get_meta( '_dxps_fbc' ),
			// Browser ID saved at checkout plus the account ID: both identify the same buyer.
			'external_id'       => array_values( array_filter( array( $order->get_meta( '_dxps_eid' ), $from_order['external_id'] ?? '' ) ) ),
		) ) );
		$params['_dxps_marketing_allowed'] = true;
		$params['_dxps_event_time'] = $event_time;
		if ( $order->get_meta( '_dxps_sid' ) ) $params['session_id'] = (int) $order->get_meta( '_dxps_sid' );
		$params['engagement_time_msec'] = 1;
		if ( $order->get_meta( '_dxps_ip' ) ) $params['_dxps_ip'] = (string) $order->get_meta( '_dxps_ip' );
		$meta = $need_meta ? self::send_meta( 'Purchase', $params, $event_id, $user, $order->get_checkout_order_received_url() ) : array( 'ok' => false, 'status' => 'already_sent_or_disabled' );
		if ( ! empty( $meta['ok'] ) ) $order->update_meta_data( '_dxps_meta_purchase_sent', 1 );

		// GA4 MP with user-provided data for better matching.
		$ga_user = DXPS_Helpers::google_user_from_order( $order );
		$ga4 = $need_ga4 ? self::send_ga4( 'purchase', $params, $order->get_meta( '_dxps_cid' ), $ga_user, (int) $order->get_customer_id() ) : array( 'ok' => false, 'status' => 'already_sent_or_disabled' );
		if ( ! empty( $ga4['ok'] ) ) $order->update_meta_data( '_dxps_ga4_purchase_sent', 1 );
		$order->update_meta_data( '_dxps_delivery_retryable', ( ! empty( $meta['retryable'] ) || ! empty( $ga4['retryable'] ) ) ? '1' : '0' );
		$order->update_meta_data( '_dxps_delivery_status', array( 'meta'=>$meta['status'], 'ga4'=>$ga4['status'], 'time'=>time() ) );
		$all_done = ( ! DXPS_Helpers::on( 'meta_enabled' ) || $order->get_meta( '_dxps_meta_purchase_sent' ) ) && ( ! DXPS_Helpers::on( 'ga4_enabled' ) || ! DXPS_Helpers::ga4_server_allowed( 'purchase' ) || $order->get_meta( '_dxps_ga4_purchase_sent' ) );
		if ( $all_done ) $order->update_meta_data( '_dxps_purchase_sent', 1 );
		$order->save();
		DXPS_Helpers::log( 'Server purchase delivery', array( 'order' => $order_id, 'meta' => $meta, 'ga4' => $ga4 ) );
		return ! empty( $meta['ok'] ) || ! empty( $ga4['ok'] );
	}

	public static function on_refund( $order_id, $refund_id ) {
		$order  = wc_get_order( $order_id );
		$refund = wc_get_order( $refund_id );
		if ( ! $order || ! $refund || $refund->get_meta( '_dxps_ga4_refund_sent' ) ) return;
		// Refunds run in the administrator's request; consent must come from the customer's checkout snapshot.
		if ( '1' !== (string) $order->get_meta( '_dxps_marketing_allowed' ) ) return;
		if ( DXPS_Helpers::sensitive_context( '', array( 'items'=>DXPS_Helpers::order_items( $order ), 'coupons'=>$order->get_coupon_codes() ) ) ) return;
		$params = array(
			'transaction_id'          => (string) $order->get_order_number(),
			'value'                   => abs( (float) $refund->get_total() ),
			'currency'                => $order->get_currency(),
			'_dxps_marketing_allowed' => true,
		);
		if ( $order->get_meta( '_dxps_ip' ) ) $params['_dxps_ip'] = (string) $order->get_meta( '_dxps_ip' );
		$res = self::send_ga4( 'refund', $params, $order->get_meta( '_dxps_cid' ), array(), (int) $order->get_customer_id() );
		if ( ! empty( $res['ok'] ) ) { $refund->update_meta_data( '_dxps_ga4_refund_sent', 1 ); $refund->save(); }
		DXPS_Helpers::log( 'Refund delivery', array( 'order' => $order_id, 'ga4' => $res ) );
	}

	public static function is_new_customer( $order ) {
		$email = $order->get_billing_email();
		if ( ! $email ) return false;
		$prior = wc_get_orders( array(
			'billing_email' => $email,
			'status'        => array( 'wc-completed', 'wc-processing' ),
			'exclude'       => array( $order->get_id() ),
			'limit'         => 1,
			'return'        => 'ids',
		) );
		return empty( $prior );
	}
}
