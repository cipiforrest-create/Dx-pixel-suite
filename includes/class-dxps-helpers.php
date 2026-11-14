<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class DXPS_Helpers {
	private static $options = null;
	public static function reset_options() { self::$options = null; }

	const EXT_COOKIE = 'pbid'; // external_id cookie (PYS-compatible name)
	const EXT_COOKIE_LEGACY = '_dxps_eid';
	const UTM_COOKIE = '_dxps_src';

	// EEA + UK + CH: consent-mode-relevant regions.
	const EEA = array( 'AT','BE','BG','HR','CY','CZ','DK','EE','FI','FR','DE','GR','HU','IE','IS','IT','LV','LI','LT','LU','MT','NL','NO','PL','PT','RO','SK','SI','ES','SE','GB','CH' );

	/** Engagement switches that are ON by default. Used when the key was never written to the database. */
	const FALLBACKS = array( 'track_scroll' => 1, 'track_time' => 1, 'track_clicks' => 1, 'time_on_page' => 30 );

	public static function opt( $key, $default = '' ) {
		if ( null === self::$options ) self::$options = (array) get_option( 'dxps_settings', array() );
		$o = self::$options;
		if ( is_array( $o ) && $o && ! array_key_exists( $key, $o ) && isset( self::FALLBACKS[ $key ] ) ) return self::FALLBACKS[ $key ];
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
		// GA4 does not deduplicate arbitrary browser/MP events by event_id. One owner only.
		if ( 'purchase' === $event_name ) return 'status' === self::opt( 'purchase_trigger', 'status' ) || self::on( 'ga4_server_purchase' );
		return in_array( $event_name, array( 'refund','login','sign_up','comment' ), true );
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
		return isset( $_COOKIE['_dxps_consent'] ) && 'granted' === $_COOKIE['_dxps_consent'];
	}

	/* ---------------- Normalisation + hashing (Meta / Google spec) ---------------- */

	public static function hash( $v ) {
		$v = trim( (string) $v );
		$v = function_exists( 'mb_strtolower' ) ? mb_strtolower( $v, 'UTF-8' ) : strtolower( $v );
		return '' === $v ? '' : hash( 'sha256', $v );
	}

	public static function norm_phone( $phone, $country = '' ) {
		$raw = trim( (string) $phone );
		if ( ! $raw || preg_match( '/[a-z]/i', $raw ) ) return '';
		$p = preg_replace( '/\D/', '', $raw );
		$prefix = array( 'BA'=>'387','RS'=>'381','HR'=>'385','SI'=>'386','DE'=>'49','AT'=>'43','CH'=>'41','ME'=>'382','MK'=>'389','GB'=>'44','US'=>'1','CA'=>'1','FR'=>'33','IT'=>'39','NL'=>'31','SE'=>'46','PL'=>'48','ES'=>'34','PT'=>'351','BE'=>'32','CZ'=>'420','SK'=>'421','HU'=>'36','RO'=>'40','BG'=>'359','GR'=>'30','IE'=>'353','DK'=>'45','NO'=>'47','FI'=>'358','AU'=>'61','NZ'=>'64','TR'=>'90' );
		$cc = $prefix[ strtoupper( $country ) ] ?? '';
		// 1.7.0 (opt-in): a local-format number with no country on the form is assumed to be in the store's country.
		if ( ! $cc && self::on( 'phone_default_country' ) ) $cc = $prefix[ self::default_country() ] ?? '';
		if ( strpos( $raw, '00' ) === 0 ) $p = substr( $p, 2 );
		elseif ( strpos( $raw, '+' ) !== 0 ) {
			if ( ! $cc ) return '';
			if ( strpos( $p, $cc ) !== 0 ) $p = $cc . ( 'IT' === strtoupper( $country ) ? $p : ltrim( $p, '0' ) );
		}
		return preg_match( '/^[1-9][0-9]{7,14}$/', $p ) ? '+' . $p : '';
	}

	/** Store base country (ISO-2, upper case) - used only by the opt-in phone fallback. */
	public static function default_country() {
		if ( function_exists( 'wc_get_base_location' ) ) {
			$loc = wc_get_base_location();
			if ( ! empty( $loc['country'] ) ) return strtoupper( $loc['country'] );
		}
		return '';
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
		return ( $site['scheme'] ?? 'https' ) . '://' . $site['host'] . ( isset( $site['port'] ) ? ':' . $site['port'] : '' ) . $path;
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
		// Meta accepts several external IDs for one person (e.g. the browser ID and the account ID).
		$eids = array();
		foreach ( (array) ( $data['external_id'] ?? array() ) as $eid ) {
			if ( is_string( $eid ) && preg_match( '/^[a-f0-9]{32,64}$/i', $eid ) ) $eids[ strtolower( $eid ) ] = 1;
		}
		$eids = array_slice( array_keys( $eids ), 0, 3 );
		if ( $eids ) $out['external_id'] = 1 === count( $eids ) ? $eids[0] : $eids;
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

	/** User-meta keys in which social-login plugins store the Facebook app-scoped user ID. */
	const FB_LOGIN_META = array( 'facebook_id', 'fb_user_id', '_fb_user_id', 'nsl_facebook_id', 'wsl_facebook_id', '_wc_social_login_facebook_identifier', 'fb_login_id', 'facebook_login_id' );

	/** Nextend Social Login keeps its IDs in its own table ({prefix}social_users), not in user meta. */
	private static function nextend_table() {
		global $wpdb;
		if ( ! is_object( $wpdb ) ) return '';
		$table  = $wpdb->prefix . 'social_users';
		$exists = get_transient( 'dxps_nsl_table' );
		if ( false === $exists ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ? 'yes' : 'no';
			set_transient( 'dxps_nsl_table', $exists, DAY_IN_SECONDS );
		}
		return 'yes' === $exists ? $table : '';
	}

	/**
	 * Only an actual Facebook Login ID; never infer DOB, gender or other demographics.
	 * Sources: user meta written by common social-login plugins, Super Socializer, and Nextend Social Login's table.
	 * (Google login has no Meta parameter of its own; it helps through the account's email address.)
	 */
	public static function user_extra( $user_id ) {
		static $cache = array();
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) return array();
		if ( isset( $cache[ $user_id ] ) ) return $cache[ $user_id ];
		$id = '';
		foreach ( apply_filters( 'dxps_fb_login_meta_keys', self::FB_LOGIN_META ) as $k ) {
			$v = (string) get_user_meta( $user_id, $k, true );
			if ( preg_match( '/^[0-9]{5,50}$/', $v ) ) { $id = $v; break; }
		}
		if ( ! $id && 'facebook' === strtolower( (string) get_user_meta( $user_id, 'thechamp_provider', true ) ) ) {
			$v = (string) get_user_meta( $user_id, 'thechamp_social_id', true );
			if ( preg_match( '/^[0-9]{5,50}$/', $v ) ) $id = $v;
		}
		if ( ! $id && ( $table = self::nextend_table() ) ) {
			global $wpdb;
			$v = (string) $wpdb->get_var( $wpdb->prepare( "SELECT identifier FROM {$table} WHERE ID = %d AND type IN ('fb','facebook') LIMIT 1", $user_id ) );
			if ( preg_match( '/^[0-9]{5,50}$/', $v ) ) $id = $v;
		}
		return $cache[ $user_id ] = $id ? array( 'fb_login_id' => $id ) : array();
	}

	/** Diagnostics: how many accounts have a Facebook Login ID that DX can read. */
	public static function fb_login_count() {
		global $wpdb;
		if ( ! is_object( $wpdb ) ) return 0;
		$keys = self::FB_LOGIN_META;
		$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key IN (" . implode( ',', array_fill( 0, count( $keys ), '%s' ) ) . ") AND meta_value REGEXP '^[0-9]{5,50}$'", $keys ) );
		if ( $table = self::nextend_table() ) $n += (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE type IN ('fb','facebook')" );
		return $n;
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
		if ( ! empty( $src['gclid'] ) || ! empty( $src['gbraid'] ) || ! empty( $src['wbraid'] ) ) return 'google / cpc';
		if ( ! empty( $src['msclkid'] ) ) return 'bing / cpc';
		if ( ! empty( $src['ttclid'] ) )  return 'tiktok / paid';
		if ( ! empty( $src['fbclid'] ) ) return 'facebook / paid';
		$ref = isset( $_SERVER['HTTP_REFERER'] ) ? wp_parse_url( $_SERVER['HTTP_REFERER'], PHP_URL_HOST ) : '';
		if ( ! $ref || false !== strpos( (string) $ref, wp_parse_url( home_url(), PHP_URL_HOST ) ) ) return 'direct';
		if ( preg_match( '/google\.|bing\.|yahoo\./', $ref ) )                 return 'organic / ' . $ref;
		if ( preg_match( '/facebook\.|instagram\.|t\.co|tiktok\./', $ref ) )   return 'social / ' . $ref;
		return 'referral / ' . $ref;
	}

	/* ---------------- First-party identifiers ---------------- */

	/**
	 * The Meta pixel hashes the browser ID before sending it, except when it already looks like a SHA-256
	 * (64 hex characters, e.g. a cookie left by PixelYourSite) - then it is sent unchanged. Mirror that here,
	 * otherwise browser and server would report two different external IDs for the same visitor.
	 */
	private static function eid_hash( $cookie ) {
		$v = strtolower( $cookie );
		return 64 === strlen( $v ) ? $v : hash( 'sha256', $v );
	}

	public static function external_id() {
		foreach ( array( self::EXT_COOKIE, self::EXT_COOKIE_LEGACY ) as $c ) {
			if ( ! empty( $_COOKIE[ $c ] ) && is_string( $_COOKIE[ $c ] ) && preg_match( '/^[a-f0-9]{32,64}$/i', $_COOKIE[ $c ] ) ) return self::eid_hash( $_COOKIE[ $c ] );
		}
		return is_user_logged_in() ? hash( 'sha256', 'dxps.user.' . get_current_user_id() ) : '';
	}

	/** Every external ID known for this visitor: browser ID cookie plus, when logged in, the account ID. */
	public static function external_ids() {
		$ids = array();
		foreach ( array( self::EXT_COOKIE, self::EXT_COOKIE_LEGACY ) as $c ) {
			if ( ! empty( $_COOKIE[ $c ] ) && is_string( $_COOKIE[ $c ] ) && preg_match( '/^[a-f0-9]{32,64}$/i', $_COOKIE[ $c ] ) ) { $ids[] = self::eid_hash( $_COOKIE[ $c ] ); break; }
		}
		if ( is_user_logged_in() ) $ids[] = hash( 'sha256', 'dxps.user.' . get_current_user_id() );
		return array_values( array_unique( $ids ) );
	}

	/** How long hashed matching data is remembered per browser ID. Default 10 minutes; opt-in up to 180 days. */
	public static function retention() {
		$days = max( 0, min( 180, (int) self::opt( 'am_retention_days', 0 ) ) );
		return $days ? $days * DAY_IN_SECONDS : 10 * MINUTE_IN_SECONDS;
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
		unset( $data['client_ip_address'], $data['client_user_agent'], $data['fbp'], $data['fbc'], $data['external_id'] ); // matching hashes only
		if ( ! $data ) return;
		set_transient( 'dxps_eid_' . substr( $eid, 0, 32 ), $data, self::retention() );
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
		if ( $live && $data != $remembered ) self::remember_user_data( $data );
		return $data;
	}

	public static function fbp() { return self::valid_user_data( array( 'fbp' => $_COOKIE['_fbp'] ?? '' ) )['fbp'] ?? ''; }

	public static function fbc() {
		$v = isset( $_COOKIE['_fbc'] ) && is_string( $_COOKIE['_fbc'] ) ? $_COOKIE['_fbc'] : '';
		// A new ad click (a different fbclid) replaces the stored click ID - Meta attributes to the latest click.
		if ( ! empty( $_GET['fbclid'] ) && is_string( $_GET['fbclid'] ) && preg_match( '/^[A-Za-z0-9_-]{6,400}$/', $_GET['fbclid'] ) ) {
			$suffix = '.' . $_GET['fbclid'];
			if ( ! $v || substr( $v, -strlen( $suffix ) ) !== $suffix ) $v = 'fb.1.' . round( microtime( true ) * 1000 ) . $suffix;
		}
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

	/** A descriptive label, or '' when it would reveal a health condition (never sent to any platform). */
	public static function safe_label( $text ) {
		$text = trim( wp_strip_all_tags( (string) $text ) );
		return ( '' === $text || self::sensitive_context( '', array( $text ) ) ) ? '' : $text;
	}

	/** Brand for product events: the product's own catalogue brand, else the configured store brand. */
	public static function item_brand( $product, $parent_id = 0 ) {
		if ( ! self::on( 'track_brand' ) ) return '';
		$brand = $product ? (string) $product->get_meta( '_dxps_brand' ) : '';
		if ( '' === $brand && $parent_id && function_exists( 'wc_get_product' ) && ( $parent = wc_get_product( $parent_id ) ) ) $brand = (string) $parent->get_meta( '_dxps_brand' );
		return self::safe_label( '' !== $brand ? $brand : self::opt( 'brand_name', 'Eynna Hair' ) );
	}

	/** First product category of the (parent) product. Variations have no categories of their own. */
	private static function item_category( $product_id ) {
		$cats = wc_get_product_terms( $product_id, 'product_cat', array( 'fields' => 'names' ) );
		return ( is_wp_error( $cats ) || ! $cats ) ? '' : self::safe_label( $cats[0] );
	}

	public static function product_item( $product, $qty = 1 ) {
		if ( ! $product instanceof WC_Product ) return null;
		$parent_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : 0;
		return array_filter( array(
			'id'       => self::pid( $product ),
			'content_type' => $product->is_type( 'variable' ) ? 'product_group' : 'product',
			'brand'    => self::item_brand( $product, $parent_id ),
			'name'     => $product->get_name(),
			'price'    => (float) wc_get_price_to_display( $product ),
			'quantity' => (int) $qty,
			'category' => self::item_category( $parent_id ?: $product->get_id() ),
			'variant'  => $parent_id ? self::safe_label( wc_get_formatted_variation( $product, true, false, false ) ) : '',
		), function ( $v ) { return '' !== $v; } );
	}

	public static function order_items( $order ) {
		$items = array();
		foreach ( $order->get_items() as $it ) {
			$p = $it->get_product();
			$items[] = array_filter( array(
				'id'       => $p ? self::pid( $p ) : (string) $it->get_product_id(),
				'brand'    => self::item_brand( $p, $it->get_variation_id() ? $it->get_product_id() : 0 ),
				'name'     => $it->get_name(),
				'price'    => (float) $order->get_item_total( $it, true ),
				'quantity' => (int) $it->get_quantity(),
				'category' => $it->get_product_id() ? self::item_category( $it->get_product_id() ) : '',
				'variant'  => ( $p && $p->is_type( 'variation' ) ) ? self::safe_label( wc_get_formatted_variation( $p, true, false, false ) ) : '',
			), function ( $v ) { return '' !== $v; } );
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
			'discount'           => (float) $order->get_discount_total(),
		);
	}

	/* ---------------- Per-pageload event queue (session) ---------------- */

	public static function queue_event( $name, $params ) {
		if ( self::excluded_role() || ! self::marketing_allowed() ) return;
		if ( function_exists( 'WC' ) && WC()->session ) {
			$q   = WC()->session->get( 'dxps_queue', array() );
			$q[] = array( 'name' => $name, 'params' => $params, 'event_id' => self::event_id( $name ) );
			WC()->session->set( 'dxps_queue', array_slice( $q, -30 ) );
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

	/* ---------------- 1.8.0: geo fill-in + content tracking ---------------- */

	/**
	 * Opt-in: hashed country (and optionally city / region / ZIP) from Cloudflare's visitor-location headers, used
	 * only where the customer has not given that field. Approximate, and derived from the IP Meta already receives.
	 */
	public static function geo_hashes() {
		if ( ! apply_filters( 'dxps_trust_proxy_headers', false ) ) return array();
		$mode = (string) self::opt( 'geo_enrich' );
		if ( ! in_array( $mode, array( 'country', 'full' ), true ) ) return array();
		$raw = array();
		$c = self::country();
		if ( preg_match( '/^[A-Z]{2}$/', $c ) && 'T1' !== $c ) $raw['country'] = $c;
		if ( 'full' === $mode ) {
			foreach ( array( 'ct' => 'HTTP_CF_IPCITY', 'st' => 'HTTP_CF_REGION_CODE', 'zp' => 'HTTP_CF_POSTAL_CODE' ) as $k => $h ) {
				if ( ! empty( $_SERVER[ $h ] ) && is_string( $_SERVER[ $h ] ) ) $raw[ $k ] = sanitize_text_field( wp_unslash( $_SERVER[ $h ] ) );
			}
		}
		return $raw ? self::matching_hashes( $raw ) : array();
	}

	/** Post types selected for content tracking. */
	public static function content_types() {
		return array_values( array_diff( array_filter( array_map( 'sanitize_key', explode( ',', (string) self::opt( 'content_types' ) ) ) ), array( 'product', 'attachment' ) ) );
	}

	private static function is_store_page() {
		if ( ! function_exists( 'is_woocommerce' ) ) return false;
		return is_woocommerce() || is_cart() || is_checkout() || is_account_page();
	}

	/**
	 * Content context of the current page, or null: array( type, id, group ).
	 * type: article (posts), page, service (service post types or pages under a configured service path), or the
	 * post type key for other custom types.
	 */
	public static function content_context() {
		static $done = false, $ctx = null;
		if ( $done ) return $ctx;
		$done = true;
		$types = self::content_types();
		if ( ! $types || ! is_singular() || self::is_store_page() ) return null;
		$pt = (string) get_post_type();
		if ( ! in_array( $pt, $types, true ) ) return null;
		$type = 'post' === $pt ? 'article' : $pt;
		if ( preg_match( '/servic|uslug|tretman|treatment/i', $pt ) ) $type = 'service';
		if ( 'service' !== $type ) {
			$path = '/' . trim( (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH ), '/' );
			foreach ( preg_split( '/\r?\n/', (string) self::opt( 'content_service_paths' ) ) as $line ) {
				$line = trim( $line );
				if ( '' === $line ) continue;
				$prefix = '*' === substr( $line, -1 );
				$want   = '/' . trim( $prefix ? substr( $line, 0, -1 ) : $line, '/' );
				if ( $prefix ? 0 === strpos( $path . '/', rtrim( $want, '/' ) . '/' ) : $path === $want ) { $type = 'service'; break; }
			}
		}
		$group = '';
		if ( 'post' === $pt && function_exists( 'get_the_category' ) ) {
			$cats = get_the_category();
			if ( $cats && ! empty( $cats[0]->name ) ) $group = (string) $cats[0]->name;
		}
		$ctx = array( 'type' => $type, 'id' => (int) get_the_ID(), 'group' => $group );
		return $ctx;
	}

	/**
	 * Meta event names for content events. "standard" uses ViewContent / ViewCategory (best for sites without a
	 * shop); "custom" keeps them apart from product events (ContentView / ContentList). auto = by WooCommerce.
	 */
	public static function content_meta_names() {
		$mode = (string) self::opt( 'content_meta_event', 'auto' );
		if ( 'standard' !== $mode && 'custom' !== $mode ) $mode = class_exists( 'WooCommerce' ) ? 'custom' : 'standard';
		return 'standard' === $mode
			? array( 'view_content' => 'ViewContent', 'view_content_list' => 'ViewCategory', 'content_engaged' => 'ContentEngaged' )
			: array( 'view_content' => 'ContentView', 'view_content_list' => 'ContentList', 'content_engaged' => 'ContentEngaged' );
	}

	/* ---------------- 1.7.0: data quality ---------------- */

	/** Known crawlers, monitors and headless browsers. Used only when "Block bots" is on. */
	public static function is_bot( $ua = null ) {
		if ( null === $ua ) $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
		if ( '' === trim( $ua ) ) return true;
		return (bool) preg_match( '~bot[/;]|adsbot|crawl|spider|slurp|headless|lighthouse|pagespeed|gtmetrix|pingdom|uptimerobot|statuscake|facebookexternalhit|facebookcatalog|scrape|curl/|wget|python-requests|phantomjs|ahrefs|semrush|bytespider~i', $ua );
	}

	/** True when the logged-in user has a role excluded from tracking (staff, test accounts). */
	public static function excluded_role() {
		$roles = array_filter( array_map( 'sanitize_key', explode( ',', (string) self::opt( 'exclude_roles' ) ) ) );
		if ( ! $roles || ! is_user_logged_in() ) return false;
		$u = wp_get_current_user();
		return $u && array_intersect( $roles, (array) $u->roles ) ? true : false;
	}

	/** Purchase value: order total, or (opt-in) the total without tax and shipping. */
	public static function order_value( $order ) {
		$total = (float) $order->get_total();
		if ( 'net' !== self::opt( 'purchase_value', 'total' ) ) return $total;
		return round( max( 0, $total - (float) $order->get_total_tax() - (float) $order->get_shipping_total() ), 2 );
	}

	/** Meta standard events a custom rule may name (case-insensitive in settings). */
	const META_STANDARD = array( 'lead'=>'Lead', 'contact'=>'Contact', 'schedule'=>'Schedule', 'subscribe'=>'Subscribe', 'starttrial'=>'StartTrial', 'submitapplication'=>'SubmitApplication', 'completeregistration'=>'CompleteRegistration', 'findlocation'=>'FindLocation', 'customizeproduct'=>'CustomizeProduct', 'donate'=>'Donate', 'viewcontent'=>'ViewContent', 'addtowishlist'=>'AddToWishlist' );

	/**
	 * Custom event rules. One per line:  trigger | event_name | options
	 *   trigger : a CSS selector (click), or click:<sel>, url:<path or path*>, scroll:<percent>, hover:<sel>,
	 *             submit:<form sel>, visits:<n>, copy
	 *   options : value=79, currency=EUR, value_param=amount, block=30 (minutes), need_fbc=1, device=mobile|desktop
	 */
	public static function custom_events() {
		static $cache = null;
		if ( null !== $cache ) return $cache;
		$cache = array();
		foreach ( preg_split( '/\r?\n/', (string) self::opt( 'custom_events' ) ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line ) );
			if ( count( $parts ) < 2 || '' === $parts[0] || ! preg_match( '/^[a-zA-Z0-9_]{2,40}$/', $parts[1] ) ) continue;
			$type = 'click'; $arg = $parts[0];
			if ( 'copy' === strtolower( $arg ) ) { $type = 'copy'; $arg = ''; }
			elseif ( preg_match( '/^(click|url|scroll|hover|submit|visits):\s*(.+)$/i', $arg, $m ) ) { $type = strtolower( $m[1] ); $arg = trim( $m[2] ); }
			if ( in_array( $type, array( 'scroll', 'visits' ), true ) ) {
				$arg = (int) $arg;
				if ( $arg < 1 || ( 'scroll' === $type && $arg > 100 ) ) continue;
			}
			$name = strtolower( $parts[1] );
			$opts = array();
			foreach ( preg_split( '/[\s,]+/', (string) ( $parts[2] ?? '' ), -1, PREG_SPLIT_NO_EMPTY ) as $kv ) {
				$kv = explode( '=', $kv, 2 ); $k = strtolower( $kv[0] ); $v = $kv[1] ?? '1';
				if ( 'value' === $k && is_numeric( $v ) && $v >= 0 )                         $opts['value'] = (float) $v;
				elseif ( 'currency' === $k && preg_match( '/^[A-Za-z]{3}$/', $v ) )          $opts['currency'] = strtoupper( $v );
				elseif ( 'value_param' === $k && preg_match( '/^[A-Za-z0-9_\-]{1,40}$/', $v ) ) $opts['value_param'] = $v;
				elseif ( 'block' === $k && (int) $v > 0 )                                    $opts['block'] = min( 43200, (int) $v );
				elseif ( 'need_fbc' === $k && $v )                                           $opts['need_fbc'] = 1;
				elseif ( 'device' === $k && in_array( strtolower( $v ), array( 'mobile', 'desktop' ), true ) ) $opts['device'] = strtolower( $v );
			}
			$cache[] = array( 'type' => $type, 'arg' => $arg, 'name' => $name, 'meta' => self::META_STANDARD[ $name ] ?? '', 'opts' => $opts );
		}
		return $cache;
	}

	/** URL-parameter identity map (opt-in). Lines: url_parameter | field (em, ph, fn, ln, name, ct, st, zp, country). */
	public static function url_identity() {
		$out = array();
		foreach ( preg_split( '/\r?\n/', (string) self::opt( 'url_identity' ) ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line ) );
			if ( 2 === count( $parts ) && preg_match( '/^[A-Za-z0-9_\-]{1,40}$/', $parts[0] ) && in_array( strtolower( $parts[1] ), array( 'em','ph','fn','ln','name','ct','st','zp','country' ), true ) ) {
				$out[] = array( $parts[0], strtolower( $parts[1] ) );
			}
		}
		return array_slice( $out, 0, 20 );
	}

	/**
	 * Diagnostics ring buffer (opt-in): which server events were sent and which matching FIELDS they carried.
	 * Field names only - never values, hashes, IPs or tokens.
	 */
	public static function log_event( $entry ) {
		if ( ! self::on( 'event_log' ) ) return;
		$log = get_option( 'dxps_event_log', array() );
		if ( ! is_array( $log ) ) $log = array();
		$log[] = array( 't' => time() ) + $entry;
		if ( count( $log ) > 200 ) $log = array_slice( $log, -200 );
		update_option( 'dxps_event_log', $log, false );
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
	public static function client_ip() {
		// Trust only the web server's resolved address. A proxy integration can explicitly override it.
		$ip = apply_filters( 'dxps_client_ip', $_SERVER['REMOTE_ADDR'] ?? '' );
		return is_string( $ip ) && filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}
	public static function google_email( $email ) {
		$email = strtolower( trim( (string) $email ) );
		if ( ! is_email( $email ) ) return '';
		list( $local, $domain ) = explode( '@', $email, 2 );
		if ( in_array( $domain, array( 'gmail.com','googlemail.com' ), true ) ) $local = str_replace( '.', '', $local );
		return $local . '@' . $domain;
	}
	public static function google_user_from_order( $order ) {
		$email = self::google_email( $order->get_billing_email() );
		$phone = self::norm_phone( $order->get_billing_phone(), $order->get_billing_country() );
		$address = array_filter( array(
			'sha256_first_name'=>self::hash( $order->get_billing_first_name() ),
			'sha256_last_name'=>self::hash( $order->get_billing_last_name() ),
			'sha256_street'=>self::hash( trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() ) ),
			'city'=>$order->get_billing_city(), 'region'=>$order->get_billing_state(),
			'postal_code'=>$order->get_billing_postcode(), 'country'=>strtoupper( $order->get_billing_country() ),
		) );
		return array_filter( array( 'sha256_email_address'=>$email ? hash( 'sha256', $email ) : '', 'sha256_phone_number'=>$phone ? hash( 'sha256', $phone ) : '', 'address'=>$address ) );
	}
	public static function ga_session_id() {
		$id = preg_replace( '/^G-/', '', (string) self::opt( 'ga4_id' ) );
		$c = $_COOKIE['_ga_' . $id] ?? '';
		if ( ! is_string( $c ) ) return '';
		if ( preg_match( '/^GS1\.\d\.(\d+)/', $c, $m ) || preg_match( '/^GS2\.\d\.s(\d+)/', $c, $m ) ) return $m[1];
		return '';
	}

}
