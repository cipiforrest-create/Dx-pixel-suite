<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class DXPS_Helpers {

	const EXT_COOKIE = 'pbid'; // external_id cookie (PYS-compatible name)
	const EXT_COOKIE_LEGACY = '_dxps_eid';
	const UTM_COOKIE = '_dxps_src';

	// EEA + UK + CH: consent-mode-relevant regions.
	const EEA = array( 'AT','BE','BG','HR','CY','CZ','DK','EE','FI','FR','DE','GR','HU','IE','IS','IT','LV','LI','LT','LU','MT','NL','NO','PL','PT','RO','SK','SI','ES','SE','GB','CH' );

	public static function opt( $key, $default = '' ) {
		static $o = null;
		if ( null === $o ) $o = get_option( 'dxps_settings', array() );
		return isset( $o[ $key ] ) && '' !== $o[ $key ] ? $o[ $key ] : $default;
	}

	public static function on( $key ) {
		return (bool) self::opt( $key, 0 );
	}

	/** GTM is only "in charge" when it is enabled AND has a container ID. */
	public static function gtm_active() {
		return self::on( 'gtm_enabled' ) && '' !== (string) self::opt( 'gtm_id' );
	}

	/**
	 * Direct Google mode (1.6.0): DX runs the enriched event engine itself and sends GA4 / Google Ads events
	 * with gtag. Only effective while GTM is off, so the same events can never be sent twice.
	 */
	public static function engine_direct() {
		return self::on( 'engine_direct' ) && ! self::gtm_active()
			&& ( ( self::on( 'ga4_enabled' ) && self::opt( 'ga4_id' ) ) || ( self::on( 'gads_enabled' ) && self::opt( 'gads_conversion_id' ) ) );
	}

	/**
	 * Which events may go to GA4 through the server (Measurement Protocol). In Direct Google mode the browser engine
	 * already sends every event, so server copies would double-count - only purchase/refund can be opted in.
	 */
	public static function ga4_server_allowed( $event_name ) {
		if ( ! self::engine_direct() ) return true;
		return in_array( $event_name, array( 'purchase', 'refund' ), true ) && self::on( 'ga4_server_purchase' );
	}

	/* ---------------- Geo / consent ---------------- */

	public static function country() {
		// Cloudflare provides this on every proxied request.
		if ( ! empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) && 'XX' !== $_SERVER['HTTP_CF_IPCOUNTRY'] ) {
			return strtoupper( sanitize_text_field( $_SERVER['HTTP_CF_IPCOUNTRY'] ) );
		}
		return '';
	}

	public static function is_eea() {
		$c = self::country();
		return $c && in_array( $c, self::EEA, true );
	}

	/**
	 * Whether marketing tags may fire for this request.
	 * Consent mode OFF  -> always true.
	 * Consent mode ON   -> non-EEA true by default; EEA requires the consent cookie.
	 */
	public static function marketing_allowed() {
		// Explicit withdrawal always wins, including when regional defaults are disabled.
		if ( isset( $_COOKIE['_dxps_consent'] ) && 'denied' === $_COOKIE['_dxps_consent'] ) return false;
		if ( ! self::on( 'consent_mode' ) ) return true;
		if ( ! self::is_eea() ) return true;
		return isset( $_COOKIE['_dxps_consent'] ) && 'granted' === $_COOKIE['_dxps_consent'];
	}

	/* ---------------- Normalisation + hashing (Meta / Google spec) ---------------- */

	public static function hash( $v ) {
		$v = trim( strtolower( (string) $v ) );
		return '' === $v ? '' : hash( 'sha256', $v );
	}

	public static function norm_phone( $phone, $country = '' ) {
		$raw = trim( (string) $phone );
		if ( ! $raw || preg_match( '/[a-z]/i', $raw ) ) return '';
		$p = preg_replace( '/\D/', '', $raw );
		$prefix = array( 'BA'=>'387','RS'=>'381','HR'=>'385','SI'=>'386','DE'=>'49','AT'=>'43','CH'=>'41','ME'=>'382','MK'=>'389','GB'=>'44','US'=>'1','CA'=>'1','FR'=>'33','IT'=>'39','NL'=>'31','SE'=>'46' );
		$cc = $prefix[ strtoupper( $country ) ] ?? '';
		if ( strpos( $raw, '00' ) === 0 ) $p = substr( $p, 2 );
		elseif ( strpos( $raw, '+' ) !== 0 ) {
			if ( ! $cc ) return '';
			if ( strpos( $p, $cc ) !== 0 ) $p = $cc . ltrim( $p, '0' );
		}
		return preg_match( '/^[1-9][0-9]{7,14}$/', $p ) ? '+' . $p : '';
	}

	public static function matching_value( $key, $value, $country = '' ) {
		if ( ! is_scalar( $value ) ) return '';
		$v = trim( (string) $value );
		if ( strlen( $v ) > 250 ) return '';
		$v = function_exists( 'mb_strtolower' ) ? mb_strtolower( $v, 'UTF-8' ) : strtolower( $v );
		if ( 'em' === $key ) return is_email( $v ) ? $v : '';
		if ( 'ph' === $key ) return ltrim( self::norm_phone( $v, $country ), '+' );
		if ( 'country' === $key ) return preg_match( '/^[a-z]{2}$/', $v ) ? $v : '';
		if ( in_array( $key, array( 'fn', 'ln', 'ct', 'st' ), true ) ) return preg_replace( '/[^\p{L}]/u', '', $v );
		if ( 'zp' === $key ) { $v = preg_replace( '/[^a-z0-9]/', '', $v ); return strtoupper( $country ) === 'US' ? substr( $v, 0, 5 ) : $v; }
		return '';
	}

	public static function matching_hashes( $raw ) {
		$out = array(); $country = self::matching_value( 'country', $raw['country'] ?? '' );
		foreach ( array( 'em','ph','fn','ln','ct','st','zp','country' ) as $k ) {
			$v = self::matching_value( $k, $raw[ $k ] ?? '', $country );
			if ( '' !== $v ) $out[ $k ] = hash( 'sha256', $v );
		}
		return $out;
	}

	public static function sensitive_context( $url, $params = array() ) {
		$text = rawurldecode( (string) $url ) . ' ' . wp_json_encode( $params );
		return (bool) preg_match( '/alopeci|kemoterap|chemotherap|onkolog|leukem|cancer|psorij|dermatit|\bdiagnos|\bdiagnostic|\bmedical|\bpatient|\brak\b/iu', $text );
	}

	public static function safe_event_url( $url ) {
		$p = wp_parse_url( (string) $url ); $site = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $p ) || empty( $p['host'] ) || strtolower( $p['host'] ) !== strtolower( $site['host'] ) ) return home_url( '/' );
		$path = $p['path'] ?? '/';
		$path = preg_replace( '~/(order-received|order-pay)/[0-9]+/?$~', '/', $path );
		return ( $site['scheme'] ?? 'https' ) . '://' . $site['host'] . $path;
	}

	/** Keep only validated matching fields, never custom/free-text user data. */
	public static function valid_user_data( $data ) {
		$out = array();
		foreach ( array( 'em','ph','fn','ln','ct','st','zp','country' ) as $k ) {
			$v = $data[ $k ] ?? '';
			if ( is_string( $v ) && preg_match( '/^[a-f0-9]{64}$/i', $v ) ) $out[ $k ] = strtolower( $v );
		}
		foreach ( array( 'fbp','fbc' ) as $k ) {
			$v = $data[ $k ] ?? '';
			if ( is_string( $v ) && strlen( $v ) <= 500 && preg_match( '/^fb\.[0-9]\.[0-9]{13}\.[A-Za-z0-9_-]+$/', $v ) ) $out[ $k ] = $v;
		}
		$ip = $data['client_ip_address'] ?? '';
		if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) $out['client_ip_address'] = $ip;
		$ua = $data['client_user_agent'] ?? '';
		if ( is_string( $ua ) && $ua ) $out['client_user_agent'] = substr( sanitize_text_field( $ua ), 0, 500 );
		$eid = $data['external_id'] ?? '';
		if ( is_string( $eid ) && preg_match( '/^[a-f0-9]{32,64}$/i', $eid ) ) $out['external_id'] = strtolower( $eid );
		$fb = $data['fb_login_id'] ?? '';
		if ( is_string( $fb ) && preg_match( '/^[0-9]{5,50}$/', $fb ) ) $out['fb_login_id'] = $fb;
		return $out;
	}

	/**
	 * Hashed user_data array from a WC_Order or the current user.
	 * Keys follow Meta CAPI naming; GA4/Ads mapping happens in the senders.
	 */
	public static function user_data_from_order( $order ) {
		if ( ! $order instanceof WC_Order ) return array();
		$d = self::matching_hashes( array( 'em'=>$order->get_billing_email(), 'ph'=>$order->get_billing_phone(), 'fn'=>$order->get_billing_first_name(), 'ln'=>$order->get_billing_last_name(), 'ct'=>$order->get_billing_city(), 'st'=>$order->get_billing_state(), 'zp'=>$order->get_billing_postcode(), 'country'=>$order->get_billing_country() ) );
		if ( $order->get_customer_id() ) {
			$d['external_id'] = hash( 'sha256', 'dxps.user.' . $order->get_customer_id() );
			$d = array_merge( $d, self::user_extra( $order->get_customer_id() ) );
		}
		return $d;
	}

	/** Only an actual Facebook Login ID; never infer DOB, gender or other demographics. */
	public static function user_extra( $user_id ) {
		foreach ( apply_filters( 'dxps_fb_login_meta_keys', array( 'facebook_id','fb_user_id','_fb_user_id','nsl_facebook_id','wsl_facebook_id' ) ) as $k ) {
			$v = (string) get_user_meta( $user_id, $k, true );
			if ( preg_match( '/^[0-9]{5,50}$/', $v ) ) return array( 'fb_login_id' => $v );
		}
		return array();
	}

	/**
	 * Maximum available customer data for the current visitor:
	 * logged-in profile OR guest WooCommerce session billing (filled at checkout).
	 * All hashed per Meta spec — used on every relayed event to lift EMQ.
	 */
	/**
	 * Plain (normalized, UNHASHED) user data for BROWSER-side Advanced Matching only.
	 *
	 * The Meta pixel JS library normalizes and SHA-256 hashes whatever you pass to
	 * fbq('init', id, {...}) itself. Passing already-hashed values means it hashes the
	 * hash — the resulting double-hash never matches any Meta user record, so browser
	 * Advanced Matching silently contributes nothing to Event Match Quality.
	 *
	 * Server-side CAPI is the opposite: it requires pre-hashed values. That asymmetry is
	 * why these are two separate methods — do NOT feed this one to the CAPI sender.
	 */
	public static function user_data_plain() {
		if ( ! self::marketing_allowed() ) return array();
		$raw = array(); $u = wp_get_current_user();
		if ( $u && $u->exists() ) $raw = array( 'em'=>$u->user_email, 'fn'=>$u->first_name, 'ln'=>$u->last_name );
		if ( function_exists( 'WC' ) && WC()->customer ) {
			$c = WC()->customer;
			$billing = array( 'em'=>$c->get_billing_email(), 'ph'=>$c->get_billing_phone(), 'fn'=>$c->get_billing_first_name(), 'ln'=>$c->get_billing_last_name(), 'ct'=>$c->get_billing_city(), 'st'=>$c->get_billing_state(), 'zp'=>$c->get_billing_postcode(), 'country'=>$c->get_billing_country() );
			foreach ( $billing as $k=>$v ) if ( $v ) $raw[ $k ] = $v;
		}
		$out = array();
		foreach ( $raw as $k=>$v ) { $n = self::matching_value( $k, $v, $raw['country'] ?? '' ); if ( $n ) $out[ $k ] = $n; }
		return $out;
	}

	public static function user_data_current() {
		$d = self::matching_hashes( self::user_data_plain() );
		if ( is_user_logged_in() && self::marketing_allowed() ) $d = array_merge( $d, self::user_extra( get_current_user_id() ) );
		return $d;
	}

	/** Traffic source classification from first-touch data + referrer. */
	public static function traffic_source() {
		$src = array();
		if ( ! empty( $_COOKIE[ self::UTM_COOKIE ] ) ) {
			$src = (array) json_decode( urldecode( wp_unslash( $_COOKIE[ self::UTM_COOKIE ] ) ), true );
		}
		if ( ! empty( $src['utm_source'] ) ) return sanitize_text_field( $src['utm_source'] . ( empty( $src['utm_medium'] ) ? '' : ' / ' . $src['utm_medium'] ) );
		if ( ! empty( $src['gclid'] ) )  return 'google / cpc';
		if ( ! empty( $src['fbclid'] ) ) return 'facebook / paid';
		$ref = isset( $_SERVER['HTTP_REFERER'] ) ? wp_parse_url( $_SERVER['HTTP_REFERER'], PHP_URL_HOST ) : '';
		if ( ! $ref || false !== strpos( (string) $ref, wp_parse_url( home_url(), PHP_URL_HOST ) ) ) return 'direct';
		if ( preg_match( '/google\.|bing\.|yahoo\./', $ref ) )                 return 'organic / ' . $ref;
		if ( preg_match( '/facebook\.|instagram\.|t\.co|tiktok\./', $ref ) )   return 'social / ' . $ref;
		return 'referral / ' . $ref;
	}

	/* ---------------- First-party identifiers ---------------- */

	public static function external_id() {
		foreach ( array( self::EXT_COOKIE, self::EXT_COOKIE_LEGACY ) as $c ) {
			if ( ! empty( $_COOKIE[ $c ] ) && preg_match( '/^[a-f0-9]{32,64}$/i', $_COOKIE[ $c ] ) ) return strtolower( $_COOKIE[ $c ] );
		}
		return is_user_logged_in() ? hash( 'sha256', 'dxps.user.' . get_current_user_id() ) : '';
	}

	/**
	 * WP-transient storage for external_id -> hashed customer data (10 minutes,
	 * auto-expiring row in the database). Lets guests keep full Advanced
	 * Matching data across requests (e.g. after entering checkout fields),
	 * which lifts Meta EMQ on every event.
	 */
	public static function remember_user_data( $data ) {
		$eid = self::external_id();
		if ( ! self::marketing_allowed() || ! $eid || ! $data ) return;
		$data = self::valid_user_data( $data );
		set_transient( 'dxps_eid_' . substr( $eid, 0, 32 ), $data, 10 * MINUTE_IN_SECONDS );
	}

	public static function recalled_user_data() {
		$eid = self::external_id();
		if ( ! $eid ) return array();
		if ( ! self::marketing_allowed() ) { delete_transient( 'dxps_eid_' . substr( $eid, 0, 32 ) ); return array(); }
		$d = get_transient( 'dxps_eid_' . substr( $eid, 0, 32 ) );
		return is_array( $d ) ? self::valid_user_data( $d ) : array();
	}

	/** Full user data: live sources merged with the 10-minute transient recall. */
	public static function user_data_full() {
		$live = self::user_data_current();
		$remembered = self::recalled_user_data();
		if ( ! empty( $remembered['em'] ) && ! empty( $live['em'] ) && $remembered['em'] !== $live['em'] ) $remembered = array();
		$data = array_merge( $remembered, $live );
		if ( $live ) self::remember_user_data( $data );
		return $data;
	}

	public static function fbp() { return self::valid_user_data( array( 'fbp' => $_COOKIE['_fbp'] ?? '' ) )['fbp'] ?? ''; }

	public static function fbc() {
		$v = $_COOKIE['_fbc'] ?? '';
		if ( ! $v && ! empty( $_GET['fbclid'] ) && is_string( $_GET['fbclid'] ) && preg_match( '/^[A-Za-z0-9_-]{6,400}$/', $_GET['fbclid'] ) ) $v = 'fb.1.' . round( microtime( true ) * 1000 ) . '.' . $_GET['fbclid'];
		return self::valid_user_data( array( 'fbc'=>$v ) )['fbc'] ?? '';
	}

	public static function ga_client_id() {
		if ( isset( $_COOKIE['_ga'] ) && preg_match( '/GA1\.\d\.(\d+\.\d+)/', $_COOKIE['_ga'], $m ) ) {
			return $m[1];
		}
		if ( ! empty( $_COOKIE['_dxps_cid'] ) ) return sanitize_text_field( $_COOKIE['_dxps_cid'] );
		return rand( 100000000, 999999999 ) . '.' . time();
	}

	/* ---------------- Item / payload building ---------------- */

	/** Product ID per configured format: post ID (default), SKU, or Facebook-for-WooCommerce (wc_post_id_X). */
	public static function pid( $product ) {
		$fmt = self::opt( 'product_id_format', 'id' );
		if ( 'sku' === $fmt && $product->get_sku() ) return (string) $product->get_sku();
		if ( 'fb_woo' === $fmt ) return 'wc_post_id_' . $product->get_id();
		return (string) $product->get_id();
	}

	public static function product_item( $product, $qty = 1 ) {
		if ( ! $product instanceof WC_Product ) return null;
		$cats = wc_get_product_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) );
		return array(
			'id'       => self::pid( $product ),
			'brand'    => self::on( 'track_brand' ) ? self::opt( 'brand_name', 'Eynna Hair' ) : '',
			'name'     => $product->get_name(),
			'price'    => (float) wc_get_price_to_display( $product ),
			'quantity' => (int) $qty,
			'category' => $cats ? $cats[0] : '',
		);
	}

	public static function order_items( $order ) {
		$items = array();
		foreach ( $order->get_items() as $it ) {
			$p = $it->get_product();
			$items[] = array(
				'id'       => $p ? self::pid( $p ) : (string) $it->get_product_id(),
				'brand'    => self::on( 'track_brand' ) ? self::opt( 'brand_name', 'Eynna Hair' ) : '',
				'name'     => $it->get_name(),
				'price'    => (float) $order->get_item_total( $it, true ),
				'quantity' => (int) $it->get_quantity(),
				'category' => '',
			);
		}
		return $items;
	}

	/**
	 * Coupons safe to transmit. Anything matching excluded keywords
	 * (default: alopeci*) is stripped from ALL platform payloads —
	 * health-condition signals must never reach ad platforms.
	 */
	public static function safe_coupons( $order ) {
		$excluded = array_filter( array_map( 'trim', explode( ',', strtolower( self::opt( 'excluded_coupons', 'alopeci' ) ) ) ) );
		$out      = array();
		foreach ( $order->get_coupon_codes() as $code ) {
			$lc   = strtolower( $code );
			$skip = false;
			foreach ( $excluded as $kw ) {
				if ( $kw && false !== strpos( $lc, $kw ) ) { $skip = true; break; }
			}
			if ( ! $skip ) $out[] = $code;
		}
		return $out;
	}

	/** Full WooCommerce parameter set for purchase events (PYS-Pro style). */
	public static function order_extras( $order ) {
		$safe_coupons = self::safe_coupons( $order );
		$tags = array();
		foreach ( $order->get_items() as $it ) {
			$tags = array_merge( $tags, wc_get_product_terms( $it->get_product_id(), 'product_tag', array( 'fields' => 'names' ) ) );
		}
		return array(
			'total'              => (float) $order->get_total(),
			'subtotal'           => (float) $order->get_subtotal(),
			'tax'                => (float) $order->get_total_tax(),
			'shipping'           => (float) $order->get_shipping_total(),
			'shipping_cost'      => (float) $order->get_shipping_total(),
			'fees'               => (float) $order->get_total_fees(),
			'num_items'          => (int) $order->get_item_count(),
			'coupon_used'        => $safe_coupons ? 'yes' : 'no',
			'coupon_name'        => implode( ',', $safe_coupons ),
			'tags'               => implode( ', ', array_slice( array_unique( $tags ), 0, 10 ) ),
			'payment_method'     => $order->get_payment_method_title(),
		);
	}

	/* ---------------- Per-pageload event queue (session) ---------------- */

	public static function queue_event( $name, $params ) {
		if ( function_exists( 'WC' ) && WC()->session ) {
			$q   = WC()->session->get( 'dxps_queue', array() );
			$q[] = array( 'name' => $name, 'params' => $params, 'event_id' => self::event_id( $name ) );
			WC()->session->set( 'dxps_queue', $q );
		}
	}

	public static function flush_queue() {
		if ( function_exists( 'WC' ) && WC()->session ) {
			$q = WC()->session->get( 'dxps_queue', array() );
			WC()->session->set( 'dxps_queue', array() );
			return $q;
		}
		return array();
	}

	public static function event_id( $name ) {
		return strtolower( $name ) . '.' . wp_generate_uuid4();
	}

	/* ---------------- Logging ---------------- */

	public static function log( $msg, $data = null ) {
		if ( ! self::on( 'debug_log' ) ) return;
		$line = '[DXPS] ' . $msg . ( null !== $data ? ' ' . wp_json_encode( $data ) : '' );
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->info( $line, array( 'source' => 'dx-pixel-suite' ) );
		} else {
			error_log( $line );
		}
	}
}
