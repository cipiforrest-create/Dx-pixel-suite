<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Front-end output:
 *  - Consent Mode v2 default (toggleable)
 *  - GTM container OR direct gtag (GA4 + Google Ads) — or both (gtm_only decides)
 *  - Meta Pixel with Advanced Matching
 *  - Page-level ecommerce events with shared event IDs (browser <-> server dedup)
 */
class DXPS_Tracking {

	public static function init() {
		if ( is_admin() ) return;

		// Server-side fbc capture (belt-and-suspenders alongside the JS capture): if a visit
		// arrives with ?fbclid= and no _fbc cookie exists yet, set it here via a real
		// Set-Cookie response header. This is what Meta's own "improve fbc coverage" diagnostic
		// is asking for — capturing it PHP-side means it survives even when an ad blocker or a
		// slow/blocked front-end script prevents our JS capture from ever running, which the
		// JS-only approach cannot cover. Must run on 'init', before any output.
		add_action( 'init', array( __CLASS__, 'capture_fbclid_early' ), 1 );

		add_action( 'wp_head', array( __CLASS__, 'head_output' ), 1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_footer', array( __CLASS__, 'footer_events' ), 20 );

		// AddToCart (non-AJAX / loop adds) + RemoveFromCart -> session queue.
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'on_add_to_cart' ), 10, 6 );
		add_action( 'woocommerce_cart_item_removed', array( __CLASS__, 'on_remove_from_cart' ), 10, 2 );
		// AJAX add-to-cart buttons: expose the display price so the browser AddToCart / add_to_cart carries a value.
		add_filter( 'woocommerce_loop_add_to_cart_args', array( __CLASS__, 'loop_price_attr' ), 10, 2 );

		// Persist attribution + identity on the order at checkout.
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_attribution' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'save_blocks_attribution' ), 10, 1 );
	}

	/* ============================ HEAD ============================ */

	/**
	 * Server-side fbc capture. Meta's own fbevents.js normally sets the _fbc cookie
	 * client-side, and our own front-end JS (dxps-front.js) also does this as a fallback —
	 * but both require the browser to actually execute that script. Some ad-blocker
	 * configurations block third-party/analytics scripts (including ours) while still
	 * allowing the page navigation itself through, which means JS-only capture silently
	 * misses those visits. Capturing fbclid here, from the request itself, and setting the
	 * cookie via a real Set-Cookie header works regardless of whether any JS ever runs.
	 */
	public static function capture_fbclid_early() {
		if ( ! DXPS_Helpers::marketing_allowed() || DXPS_Helpers::sensitive_context( $_SERVER['REQUEST_URI'] ?? '/' ) ) return;
		if ( ! empty( $_COOKIE['_fbc'] ) || empty( $_GET['fbclid'] ) || headers_sent() ) return;
		$fbc = DXPS_Helpers::fbc();
		if ( ! $fbc ) return;
		// 400 days is the hard ceiling modern browsers (Chrome/Edge) enforce on Set-Cookie
		// Expires/Max-Age regardless of what's requested, so that's the real-world maximum.
		setcookie( '_fbc', $fbc, array(
			'expires'  => time() + 400 * DAY_IN_SECONDS,
			'path'     => '/',
			'domain'   => '',
			'secure'   => is_ssl(),
			'httponly' => false, // Meta's own JS SDK needs to read this cookie too
			'samesite' => 'Lax',
		) );
		$_COOKIE['_fbc'] = $fbc; // usable immediately within this same request
	}

	/** 1.7.0: staff / test roles excluded from tracking. Decided once per request. */
	private static function off() {
		static $off = null;
		if ( null === $off ) $off = DXPS_Helpers::excluded_role();
		return $off;
	}

	public static function head_output() {
		if ( self::off() ) {
			// Keep the public JS interface alive so consent banners and theme code calling it do not error.
			echo "\n<!-- DX Pixel Suite: tracking is off for this user role -->\n<script>window.dataLayer=window.dataLayer||[];window.gtag=window.gtag||function(){};window.dxpsQ=[];window.dxpsFire=function(){};window.dxpsConsent=function(){};window.dxpsMarketingAllowed=function(){return false};window.EynnaConsent=window.EynnaConsent||{update:function(){}};</script>\n";
			return;
		}
		$gtm  = DXPS_Helpers::on( 'gtm_enabled' ) ? DXPS_Helpers::opt( 'gtm_id' ) : '';
		$ga4  = DXPS_Helpers::on( 'ga4_enabled' ) ? DXPS_Helpers::opt( 'ga4_id' ) : '';
		$aw   = DXPS_Helpers::on( 'gads_enabled' ) ? DXPS_Helpers::opt( 'gads_conversion_id' ) : '';
		$meta = DXPS_Helpers::on( 'meta_enabled' ) ? DXPS_Helpers::opt( 'meta_pixel_id' ) : '';
		// GTM-only mode is meaningless while GTM itself is off - then DX's direct GA4/Ads tags must run.
		$gtm_only = DXPS_Helpers::on( 'gtm_only' ) && '' !== $gtm;
		$engine   = DXPS_Helpers::engine_direct();
		$blocked = DXPS_Helpers::sensitive_context( home_url( $_SERVER['REQUEST_URI'] ?? '/' ), array( 'title'=>wp_get_document_title() ) );
		if ( $blocked ) $meta = '';

		echo "\n<!-- DX Pixel Suite v" . esc_html( DXPS_VERSION ) . " -->\n";
		echo "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}window.dxpsQ=[];</script>\n";

		/* ---- Consent Mode v2 default (before any tag) ---- */
		if ( DXPS_Helpers::on( 'consent_mode' ) ) {
			$eea = wp_json_encode( DXPS_Helpers::EEA );
			echo "<script>
gtag('consent','default',{ad_storage:'granted',analytics_storage:'granted',ad_user_data:'granted',ad_personalization:'granted',wait_for_update:500});
gtag('consent','default',{ad_storage:'denied',analytics_storage:'denied',ad_user_data:'denied',ad_personalization:'denied',region:$eea,wait_for_update:500});
gtag('set','url_passthrough',true);
// Shared consent bridge is emitted below, before GTM loads.
</script>\n";
		} else {
			// Existing GTM remains the browser owner until migration is validated.
		}

		echo '<script>window.dxpsConsentRequired=' . ( DXPS_Helpers::on( 'consent_mode' ) ? 'true' : 'false' ) . ';</script>';
		if ( DXPS_Helpers::on( 'block_bots' ) ) {
			// Decided in the browser because cached HTML is the same for people and crawlers.
			echo "<script>window.dxpsBot=!!navigator.webdriver||/bot[\\/;]|adsbot|crawl|spider|slurp|headless|lighthouse|pagespeed|gtmetrix|pingdom|uptimerobot|statuscake|facebookexternalhit|scrape|phantomjs|ahrefs|semrush|bytespider/i.test(navigator.userAgent||'');</script>";
		}

		// Shared interface: GTM's EynnaConsent and DX use the same withdrawal cookie.
		echo <<<'DXPS_CONSENT_BRIDGE'
<script>
(function(w){
function state(){var m=document.cookie.match(/(?:^|;\s*)_dxps_consent=(granted|denied)(?:;|$)/);if(m)return m[1];try{var s=localStorage.getItem('eh-consent');return s==='granted'||s==='denied'?s:''}catch(e){return ''}}
w.dxpsMarketingAllowed=function(){return !w.dxpsBot&&state()!=='denied'&&(!(w.dxpsConsentRequired)||state()==='granted')};
w.dxpsConsent=function(value){var g=value===true||value==='granted',s=g?'granted':'denied';document.cookie='_dxps_consent='+s+';path=/;max-age=15552000;SameSite=Lax'+(location.protocol==='https:'?';Secure':'');try{localStorage.setItem('eh-consent',s)}catch(e){}w.__ehConsentState={analytics_storage:s,ad_storage:s,ad_user_data:s,ad_personalization:s};gtag('consent','update',w.__ehConsentState);dataLayer.push({event:'consent_update',consent_state:s});if(g&&w.dxpsInitIdentity)w.dxpsInitIdentity();if(g&&w.dxpsInitMeta)w.dxpsInitMeta();document.dispatchEvent(new CustomEvent('dxps:consent',{detail:s}))};
w.EynnaConsent=w.EynnaConsent||{};w.EynnaConsent.update=w.dxpsConsent;
var saved=state();if(saved)w.dxpsConsent(saved);
})(window);
</script>
DXPS_CONSENT_BRIDGE;

		/* ---- GTM ---- */
		if ( $gtm ) {
			$gtm_e = esc_js( $gtm );
			echo "<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','{$gtm_e}');</script>\n";
		}

		/* ---- Direct gtag (GA4 + Ads) — skipped in gtm_only mode ---- */
		if ( ! $gtm_only && ( $ga4 || $aw ) ) {
			$loader = $ga4 ? $ga4 : $aw;
			echo '<script async src="https://www.googletagmanager.com/gtag/js?id=' . esc_attr( $loader ) . '"></script>' . "\n";
			echo "<script>gtag('js',new Date());\n";
			if ( $ga4 ) echo "gtag('config','" . esc_js( $ga4 ) . "');\n";
			if ( $aw )  echo "gtag('config','" . esc_js( $aw ) . "',{" . ( $engine ? 'send_page_view:false,' : '' ) . "allow_enhanced_conversions:" . ( DXPS_Helpers::on( 'gads_enhanced' ) ? 'true' : 'false' ) . "});\n";
			echo "</script>\n";
		}

		/* ---- Meta Pixel (consent-gated in EEA) ---- */
		if ( $meta ) {
			$adv = self::advanced_matching_js();
			$px  = esc_js( $meta );
			// 1.7.0 (opt-in): Meta Limited Data Use, geolocated by Meta (country 0, state 0).
			$ldu = DXPS_Helpers::on( 'meta_ldu' ) ? "fbq('dataProcessingOptions',['LDU'],0,0);" : '';
			echo "<script>
window.dxpsMetaReady=false;
window.dxpsInitMeta=function(){if(window.dxpsMetaReady||(window.dxpsMarketingAllowed&&!window.dxpsMarketingAllowed()))return;!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');window.dxpsMetaReady=true;{$ldu}fbq('init','{$px}'{$adv});var id='page_view.'+Date.now()+'.'+Math.random().toString(36).slice(2);fbq('trackSingle','{$px}','PageView',{}, {eventID:id});if(window.dxpsMetaPageView)window.dxpsMetaPageView(id);else window.dxpsPendingPageView=id;(window.dxpsQ||[]).forEach(function(e){fbq.apply(null,e);});window.dxpsQ=[];};
</script>\n";
			if ( DXPS_Helpers::marketing_allowed() ) {
				echo "<script>window.dxpsInitMeta();</script>\n";
			}
		}

		/* ---- Config for front JS ---- */
		/* ---- Global parameters attached to every event ---- */
		$utms = array();
		if ( ! empty( $_COOKIE[ DXPS_Helpers::UTM_COOKIE ] ) ) {
			$raw = (array) json_decode( urldecode( wp_unslash( $_COOKIE[ DXPS_Helpers::UTM_COOKIE ] ) ), true );
			foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' ) as $uk ) {
				if ( ! empty( $raw[ $uk ] ) ) $utms[ $uk ] = sanitize_text_field( $raw[ $uk ] );
			}
		}
		$u   = wp_get_current_user();
		$ctx = array_filter( array(
			'page_title'     => wp_get_document_title(),
			'post_type'      => is_singular() ? get_post_type() : ( is_product_category() ? 'product_cat' : ( is_shop() ? 'shop' : '' ) ),
			'post_id'        => is_singular() ? get_the_ID() : 0,
			'post_category'  => is_singular( 'product' ) ? implode( ', ', wc_get_product_terms( get_the_ID(), 'product_cat', array( 'fields' => 'names' ) ) ) : '',
			'user_role'      => ( $u && $u->exists() && $u->roles ) ? $u->roles[0] : 'guest',
			'traffic_source' => DXPS_Helpers::traffic_source(),
			'plugin'         => 'DXPixelSuite',
		) ) + $utms;

		$cfg = array(
			'relay'     => DXPS_Helpers::on( 'server_relay' ) ? esc_url_raw( rest_url( 'dxps/v1/e' ) ) : '',
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'leads'     => DXPS_Helpers::on( 'track_leads' ) ? 1 : 0,
			'scroll'    => DXPS_Helpers::on( 'track_scroll' ) ? 1 : 0,
			'timeOnPage'=> DXPS_Helpers::on( 'track_time' ) ? (int) DXPS_Helpers::opt( 'time_on_page', 30 ) : 0,
			'clicks'    => DXPS_Helpers::on( 'track_clicks' ) ? 1 : 0,
			'gadsLead'  => ( $aw && DXPS_Helpers::opt( 'gads_lead_label' ) ) ? $aw . '/' . DXPS_Helpers::opt( 'gads_lead_label' ) : '',
			'currency'  => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'BAM',
			'metaPixel' => $meta,
			'consentRequired' => DXPS_Helpers::on( 'consent_mode' ) ? 1 : 0,
			'blockedContext' => $blocked ? 1 : 0,
			'googleDirect' => ( ! $gtm_only && ( $ga4 || $aw ) ) ? 1 : 0,
			'engineDirect' => $engine ? 1 : 0,
			'ga4Id'     => $engine ? $ga4 : '',
			'adsId'     => $engine ? $aw : '',
			'ctx'       => $ctx,
			'forms'     => DXPS_Helpers::on( 'track_forms' ) ? 1 : 0,
			'downloads' => DXPS_Helpers::on( 'track_downloads' ) ? 1 : 0,
			'custom'    => self::custom_events_cfg(),
			'autoAm'    => ( DXPS_Helpers::on( 'auto_am' ) && ( $meta || $ga4 ) ) ? 1 : 0,
			// ---- 1.7.0 (all opt-in) ----
			'amAll'       => DXPS_Helpers::on( 'am_all_forms' ) ? 1 : 0,
			'urlIdentity' => DXPS_Helpers::on( 'auto_am' ) ? DXPS_Helpers::url_identity() : array(),
			'baseCountry' => DXPS_Helpers::on( 'phone_default_country' ) ? strtolower( DXPS_Helpers::default_country() ) : '',
			'fbpFallback' => ( $meta && DXPS_Helpers::on( 'fbp_fallback' ) ) ? 1 : 0,
			'formLeads'   => DXPS_Helpers::on( 'track_form_leads' ) ? 1 : 0,
			'outbound'    => DXPS_Helpers::on( 'track_outbound' ) ? 1 : 0,
			'userIngest'=> ( DXPS_Helpers::on( 'auto_am' ) && DXPS_Helpers::on( 'server_relay' ) && ( $meta || $ga4 ) ) ? esc_url_raw( rest_url( 'dxps/v1/u' ) ) : '',
		);
		echo '<script>window.dxpsCfg=' . wp_json_encode( $cfg ) . ";</script>\n";
		echo "<!-- /DX Pixel Suite -->\n";
	}

	/** Custom event rules for the browser (see DXPS_Helpers::custom_events() for the syntax). */
	private static function custom_events_cfg() {
		$out = array();
		foreach ( DXPS_Helpers::custom_events() as $ce ) {
			$out[] = array( 'sel' => 'click' === $ce['type'] ? $ce['arg'] : '', 'type' => $ce['type'], 'arg' => $ce['arg'], 'name' => $ce['name'], 'meta' => $ce['meta'], 'opts' => (object) $ce['opts'] );
		}
		return $out;
	}

	/**
	 * Meta Advanced Matching object for fbq('init').
	 *
	 * Legacy implementation sent normalized values; the Meta pixel library hashes
	 * them in the browser before transmission — nothing leaves the visitor's device
	 * unhashed. Previously these were pre-hashed here, which made the pixel hash them a
	 * second time; the double-hash matched nothing, so browser Advanced Matching was
	 * effectively dead weight and EMQ suffered. (Server CAPI still uses hashed values.)
	 */
	private static function advanced_matching_js() {
		// Customer data is never placed in cacheable HTML.
		return '';
	}
	public static function enqueue() {
		if ( self::off() ) return;
		$deps = array( 'jquery' );
		if ( DXPS_Helpers::engine_direct() ) {
			// Direct Google mode: enriched event engine (port of the GTM container's custom tags).
			wp_enqueue_script( 'dxps-google', DXPS_URL . 'assets/js/dxps-google.js', array( 'jquery' ), DXPS_VERSION, true );
			$deps[] = 'dxps-google';
		}
		wp_enqueue_script( 'dxps-front', DXPS_URL . 'assets/js/dxps-front.js', $deps, DXPS_VERSION, true );
	}

	/* ============================ EVENTS ============================ */

	public static function footer_events() {
		if ( ! function_exists( 'WC' ) || self::off() ) return;
		$events = array();

		// Session-queued events (add/remove cart from previous request).
		foreach ( DXPS_Helpers::flush_queue() as $q ) $events[] = $q;

		if ( is_product() ) {
			$item = DXPS_Helpers::product_item( wc_get_product( get_the_ID() ) );
			if ( $item ) $events[] = array( 'name' => 'view_item', 'params' => array(
				'items' => array( $item ), 'value' => $item['price'],
				'category_name' => $item['category'],
				'tags' => implode( ', ', wc_get_product_terms( get_the_ID(), 'product_tag', array( 'fields' => 'names' ) ) ),
			), 'event_id' => DXPS_Helpers::event_id( 'view_item' ) );
		} elseif ( is_product_category() || is_shop() || is_product_tag() || is_search() ) {
			$items = array(); $i = 0;
			if ( have_posts() ) {
				global $wp_query;
				foreach ( array_slice( $wp_query->posts, 0, 10 ) as $post ) {
					$it = DXPS_Helpers::product_item( wc_get_product( $post->ID ) );
					if ( $it ) { $it['index'] = ++$i; $items[] = $it; }
				}
			}
			$list = is_shop() ? 'Shop' : ( is_search() ? 'Search results' : single_term_title( '', false ) );
			if ( $items ) $events[] = array( 'name' => 'view_item_list', 'params' => array( 'item_list_name' => $list, 'items' => $items ), 'event_id' => DXPS_Helpers::event_id( 'view_item_list' ) );
			if ( is_search() && DXPS_Helpers::on( 'track_search' ) ) {
				$events[] = array( 'name' => 'search', 'params' => array( 'search_term' => get_search_query() ), 'event_id' => DXPS_Helpers::event_id( 'search' ) );
			}
		} elseif ( is_cart() && ! WC()->cart->is_empty() ) {
			$events[] = array( 'name' => 'view_cart', 'params' => self::cart_params(), 'event_id' => DXPS_Helpers::event_id( 'view_cart' ) );
		} elseif ( is_checkout() && ! is_order_received_page() && ! WC()->cart->is_empty() ) {
			$events[] = array( 'name' => 'begin_checkout', 'params' => self::cart_params(), 'event_id' => DXPS_Helpers::event_id( 'begin_checkout' ) );
		} elseif ( is_order_received_page() ) {
			$purchase = self::purchase_event();
			if ( $purchase ) $events[] = $purchase;
		}

		if ( $events ) {
			foreach ( $events as $k => $event ) {
				if ( in_array( $event['name'], array( 'view_item','view_item_list','search' ), true ) ) $events[ $k ]['_dxps_cacheable'] = true;
			}
			echo '<script>window.dxpsEvents=' . wp_json_encode( $events ) . ';if(window.dxpsFire){window.dxpsEvents.forEach(window.dxpsFire);}</script>' . "\n";
		}
	}

	private static function cart_params() {
		$items = array();
		foreach ( WC()->cart->get_cart() as $ci ) {
			$it = DXPS_Helpers::product_item( $ci['data'], $ci['quantity'] );
			if ( $it ) $items[] = $it;
		}
		return array( 'items' => $items, 'value' => (float) WC()->cart->get_total( 'edit' ) );
	}

	/**
	 * Thank-you page. Google Ads conversion + Enhanced Conversions always fire
	 * here (Google's recommended placement). Meta/GA4 Purchase fires here only
	 * in 'thankyou' mode; in 'status' mode (COD-safe) the server sends it when
	 * the order reaches the configured status.
	 */
	private static function purchase_event() {
		global $wp;
		$order_id = isset( $wp->query_vars['order-received'] ) ? absint( $wp->query_vars['order-received'] ) : 0;
		if ( ! $order_id ) return null;
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( '_dxps_browser_fired' ) ) return null;
		if ( ! DXPS_Helpers::marketing_allowed() || in_array( $order->get_status(), array( 'failed','cancelled','refunded' ), true ) ) return null;
		if ( DXPS_Helpers::sensitive_context( '', array( 'items'=>DXPS_Helpers::order_items( $order ), 'coupons'=>$order->get_coupon_codes() ) ) ) return null;
		$order->update_meta_data( '_dxps_browser_fired', 1 );
		$order->save();

		$event_id = 'purchase.' . $order_id; // stable ID => Meta dedup browser vs CAPI.
		$params   = array_merge( array(
			'transaction_id' => (string) $order->get_order_number(),
			'value'          => DXPS_Helpers::order_value( $order ),
			'currency'       => $order->get_currency(),
			'items'          => DXPS_Helpers::order_items( $order ),
			'coupon'         => implode( ',', DXPS_Helpers::safe_coupons( $order ) ),
			'new_customer'   => DXPS_Server::is_new_customer( $order ),
		), DXPS_Helpers::order_extras( $order ) );

		// Google Ads conversion + EC user data — always on thank-you.
		if ( DXPS_Helpers::on( 'gads_enabled' ) && DXPS_Helpers::opt( 'gads_conversion_id' ) && DXPS_Helpers::opt( 'gads_purchase_label' ) ) {
			$params['_gads_send_to'] = DXPS_Helpers::opt( 'gads_conversion_id' ) . '/' . DXPS_Helpers::opt( 'gads_purchase_label' );
			if ( DXPS_Helpers::on( 'gads_enhanced' ) ) {
				$params['_ec'] = array(
					'sha256_email_address' => DXPS_Helpers::hash( $order->get_billing_email() ),
					'sha256_phone_number'  => DXPS_Helpers::hash( DXPS_Helpers::norm_phone( $order->get_billing_phone(), $order->get_billing_country() ) ),
					'address'              => array(
						'sha256_first_name' => DXPS_Helpers::hash( $order->get_billing_first_name() ),
						'sha256_last_name'  => DXPS_Helpers::hash( $order->get_billing_last_name() ),
						'postal_code'       => $order->get_billing_postcode(),
						'country'           => $order->get_billing_country(),
					),
				);
			}
		}

		// Meta + GA4 purchase browser-side only in thankyou mode.
		$params['_scope'] = ( 'thankyou' === DXPS_Helpers::opt( 'purchase_trigger', 'status' ) ) ? 'all' : 'gads_only';

		return array( 'name' => 'purchase', 'params' => $params, 'event_id' => $event_id );
	}

	/* ---------- Cart hooks ---------- */

	/** dxps-front.js reads data-dxps-price on added_to_cart; without it AJAX adds were sent with no value. */
	public static function loop_price_attr( $args, $product ) {
		if ( $product instanceof WC_Product ) {
			$price = (float) wc_get_price_to_display( $product );
			if ( $price >= 0 ) {
				$args['attributes'] = isset( $args['attributes'] ) && is_array( $args['attributes'] ) ? $args['attributes'] : array();
				$args['attributes']['data-dxps-price'] = wc_format_decimal( $price, wc_get_price_decimals() );
			}
		}
		return $args;
	}

	public static function on_add_to_cart( $key, $product_id, $qty, $variation_id, $variation, $data ) {
		if ( wp_doing_ajax() ) return; // AJAX adds handled in JS via added_to_cart.
		$item = DXPS_Helpers::product_item( wc_get_product( $variation_id ?: $product_id ), $qty );
		if ( $item ) DXPS_Helpers::queue_event( 'add_to_cart', array( 'items' => array( $item ), 'value' => $item['price'] * $qty ) );
	}

	public static function on_remove_from_cart( $key, $cart ) {
		$ci = isset( $cart->removed_cart_contents[ $key ] ) ? $cart->removed_cart_contents[ $key ] : null;
		if ( ! $ci ) return;
		$item = DXPS_Helpers::product_item( wc_get_product( $ci['variation_id'] ?: $ci['product_id'] ), $ci['quantity'] );
		if ( $item ) DXPS_Helpers::queue_event( 'remove_from_cart', array( 'items' => array( $item ), 'value' => $item['price'] * $ci['quantity'] ) );
	}

	/* ---------- Attribution snapshot on the order ---------- */

	public static function save_blocks_attribution( $order ) { self::save_attribution( $order ); $order->save(); }

	public static function save_attribution( $order ) {
		// Orders placed by an excluded staff/test role are held back from server-side purchase delivery too.
		$allowed = DXPS_Helpers::marketing_allowed() && ! DXPS_Helpers::excluded_role();
		$order->update_meta_data( '_dxps_marketing_allowed', $allowed ? '1' : '0' );
		$order->update_meta_data( '_dxps_consent_recorded_at', time() );
		if ( ! $allowed ) return;
		DXPS_Helpers::remember_user_data( DXPS_Helpers::user_data_from_order( $order ) );
		$order->update_meta_data( '_dxps_fbp', DXPS_Helpers::fbp() );
		$order->update_meta_data( '_dxps_fbc', DXPS_Helpers::fbc() );
		$order->update_meta_data( '_dxps_eid', DXPS_Helpers::external_id() );
		$order->update_meta_data( '_dxps_cid', DXPS_Helpers::ga_client_id() );
		$order->update_meta_data( '_dxps_ua',  isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ), 0, 500 ) : '' );
		$order->update_meta_data( '_dxps_ip',  isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ? sanitize_text_field( $_SERVER['HTTP_CF_CONNECTING_IP'] ) : ( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( $_SERVER['REMOTE_ADDR'] ) : '' ) );
		if ( ! empty( $_COOKIE[ DXPS_Helpers::UTM_COOKIE ] ) ) {
			$order->update_meta_data( '_dxps_utm', sanitize_text_field( wp_unslash( $_COOKIE[ DXPS_Helpers::UTM_COOKIE ] ) ) );
		}
	}
}
