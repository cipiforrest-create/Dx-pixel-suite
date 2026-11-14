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

		add_action( 'wc_ajax_dxps_cart_events', array( __CLASS__, 'cart_events_ajax' ) );

		// AddToCart (non-AJAX / loop adds) + RemoveFromCart -> session queue.
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'on_add_to_cart' ), 10, 6 );
		add_action( 'woocommerce_cart_item_removed', array( __CLASS__, 'on_remove_from_cart' ), 10, 2 );
		// AJAX add-to-cart buttons: expose the display price so the browser AddToCart / add_to_cart carries a value.
		add_filter( 'woocommerce_available_variation', array( __CLASS__, 'variation_data' ), 10, 3 );
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
		if ( empty( $_GET['fbclid'] ) || headers_sent() ) return;
		$fbc = DXPS_Helpers::fbc(); // keeps the cookie for the same click, new value for a new ad click
		if ( ! $fbc || ( isset( $_COOKIE['_fbc'] ) && $_COOKIE['_fbc'] === $fbc ) ) return;
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
		if ( $blocked ) { $meta = ''; $ga4 = ''; $aw = ''; $gtm = ''; }

		echo "\n<!-- DX Pixel Suite v" . esc_html( DXPS_VERSION ) . " -->\n";
		echo "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}window.dxpsQ=[];gtag('set','page_location',location.origin+location.pathname.replace(/\\/(order-received|order-pay)\\/\\d+\\/?$/, '/'));gtag('set','page_title','Website');try{var dxRef=new URL(document.referrer);gtag('set','page_referrer',dxRef.origin+dxRef.pathname)}catch(e){gtag('set','page_referrer','')}</script>\n";

		/* ---- Consent Mode v2 default (before any tag) ---- */
		if ( DXPS_Helpers::on( 'consent_mode' ) ) {
			$eea = wp_json_encode( DXPS_Helpers::EEA );
			echo "<script>
gtag('consent','default',{ad_storage:'denied',analytics_storage:'denied',ad_user_data:'denied',ad_personalization:'denied',wait_for_update:500});
gtag('set','ads_data_redaction',true);
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
w.dxpsConsent=function(value){
var g=value===true||value==='granted',s=g?'granted':'denied';
document.cookie='_dxps_consent='+s+';path=/;max-age=15552000;SameSite=Lax'+(location.protocol==='https:'?';Secure':'');
try{localStorage.setItem('eh-consent',s)}catch(e){}
w.__ehConsentState={analytics_storage:s,ad_storage:s,ad_user_data:s,ad_personalization:s};
gtag('consent','update',w.__ehConsentState);dataLayer.push({event:'consent_update',consent_state:s});
if(typeof w.fbq==='function')w.fbq('consent',g?'grant':'revoke');
if(g){if(w.dxpsInitIdentity)w.dxpsInitIdentity();if(w.dxpsInitMeta)w.dxpsInitMeta();}
else{
 if(w.dxpsCfg&&w.dxpsCfg.forgetUrl)fetch(w.dxpsCfg.forgetUrl,{method:'POST',credentials:'same-origin',keepalive:true,headers:{'Content-Type':'application/json'},body:'{}'}).catch(function(){});
 ['pbid','_dxps_eid','_dxps_cid','_dxps_src','_dxps_lp','_fbp','_fbc'].forEach(function(n){document.cookie=n+'=;path=/;max-age=0;SameSite=Lax'});
 try{sessionStorage.removeItem('eh_customer_hashes');Object.keys(localStorage).forEach(function(k){if(k.indexOf('eh_')===0||k.indexOf('dxps_')===0)localStorage.removeItem(k)})}catch(e){}
 w.__ehHashedCustomerData=null;w.dxpsQ=[];gtag('set','user_data',null);
}
document.dispatchEvent(new CustomEvent('dxps:consent',{detail:s}));
};
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
			if ( $ga4 ) echo "gtag('config','" . esc_js( $ga4 ) . "',{send_page_view:false});\n";
			if ( $aw )  echo "gtag('config','" . esc_js( $aw ) . "',{" . 'send_page_view:false,' . "allow_enhanced_conversions:" . ( DXPS_Helpers::on( 'gads_enhanced' ) ? 'true' : 'false' ) . "});\n";
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
		$wc  = class_exists( 'WooCommerce' ); // optional since 1.8.0
		$content = DXPS_Helpers::content_context();
		$ctx = array_filter( array(
			'page_title'     => wp_get_document_title(),
			'post_type'      => is_singular() ? get_post_type() : ( $wc && is_product_category() ? 'product_cat' : ( $wc && is_shop() ? 'shop' : '' ) ),
			'post_id'        => is_singular() ? get_the_ID() : 0,
			'post_category'  => ( $wc && is_singular( 'product' ) ) ? implode( ', ', wc_get_product_terms( get_the_ID(), 'product_cat', array( 'fields' => 'names' ) ) ) : '',
			'user_role'      => ( $u && $u->exists() && $u->roles ) ? $u->roles[0] : 'guest',
			'traffic_source' => DXPS_Helpers::traffic_source(),
			'plugin'         => 'DXPixelSuite',
		) ) + $utms;

		$cfg = array(
			'relay'     => DXPS_Helpers::on( 'server_relay' ) ? esc_url_raw( rest_url( 'dxps/v1/e' ) ) : '',
			'nonce'     => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'leads'     => DXPS_Helpers::on( 'track_leads' ) ? 1 : 0,
			'scroll'    => DXPS_Helpers::on( 'track_scroll' ) ? 1 : 0,
			'timeOnPage'=> DXPS_Helpers::on( 'track_time' ) ? (int) DXPS_Helpers::opt( 'time_on_page', 30 ) : 0,
			'clicks'    => DXPS_Helpers::on( 'track_clicks' ) ? 1 : 0,
			'gadsLead'  => ( $aw && DXPS_Helpers::opt( 'gads_lead_label' ) ) ? $aw . '/' . DXPS_Helpers::opt( 'gads_lead_label' ) : '',
			'currency'  => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'BAM',
			'metaPixel' => $meta,
			'consentRequired' => DXPS_Helpers::on( 'consent_mode' ) ? 1 : 0,
			'blockedContext' => $blocked ? 1 : 0,
			'cartEventsUrl' => class_exists( 'WC_AJAX' ) ? WC_AJAX::get_endpoint( 'dxps_cart_events' ) : '',
			'gtmActive' => (bool) $gtm,
			'googleDirect' => ( ! $gtm_only && ( $ga4 || $aw ) ) ? 1 : 0,
			'engineDirect' => $engine ? 1 : 0,
			'ga4Id'     => $ga4,
			'ga4ServerPurchase' => DXPS_Helpers::ga4_server_allowed( 'purchase' ),
			'forgetUrl' => esc_url_raw( rest_url( 'dxps/v1/forget' ) ),
			'adsId'     => $aw,
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
			// ---- 1.8.0: content tracking (blog posts, pages, services) ----
			'contentMeta' => DXPS_Helpers::content_meta_names(),
			'content'     => ( $content && DXPS_Helpers::on( 'content_engaged' ) ) ? array(
				'type'   => $content['type'], 'id' => $content['id'],
				'scroll' => max( 10, min( 100, (int) DXPS_Helpers::opt( 'content_read_scroll', 50 ) ) ),
				'time'   => max( 5, min( 600, (int) DXPS_Helpers::opt( 'content_read_time', 30 ) ) ),
			) : null,
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
		if ( self::off() ) return;
		$events = function_exists( 'WC' ) ? self::store_events() : array();

		// Site search on sites without a shop (the store branch covers it otherwise).
		if ( ! function_exists( 'WC' ) && is_search() && DXPS_Helpers::on( 'track_search' ) ) {
			$events[] = array( 'name' => 'search', 'params' => array( 'search_term' => get_search_query() ), 'event_id' => DXPS_Helpers::event_id( 'search' ) );
		}

		// 1.8.0: content tracking - blog posts, pages, service pages and other selected post types.
		$content = DXPS_Helpers::content_context();
		if ( $content ) {
			$events[] = array( 'name' => 'view_content', 'params' => array_filter( array(
				'content_type' => $content['type'], 'content_id' => (string) $content['id'], 'content_group' => $content['group'],
			) ), 'event_id' => DXPS_Helpers::event_id( 'view_content' ) );
		} elseif ( DXPS_Helpers::on( 'content_archives' ) && DXPS_Helpers::content_types() && ! is_singular() && ! is_search() ) {
			$types = DXPS_Helpers::content_types();
			$blog  = in_array( 'post', $types, true ) && ( is_home() || is_category() || is_tag() );
			if ( $blog || is_post_type_archive( $types ) ) {
				$events[] = array( 'name' => 'view_content_list', 'params' => array_filter( array(
					'content_type'  => $blog ? 'article' : (string) get_query_var( 'post_type' ),
					'content_group' => is_home() ? 'Blog' : (string) wp_strip_all_tags( get_the_archive_title() ),
				) ), 'event_id' => DXPS_Helpers::event_id( 'view_content_list' ) );
			}
		}

		if ( $events ) {
			foreach ( $events as $k => $event ) {
				if ( in_array( $event['name'], array( 'view_item','view_item_list','search','view_content','view_content_list' ), true ) ) $events[ $k ]['_dxps_cacheable'] = true;
			}
			echo '<script>window.dxpsEvents=' . wp_json_encode( $events ) . ';if(window.dxpsFire){window.dxpsEvents.forEach(window.dxpsFire);}</script>' . "\n";
		}
	}

	/** WooCommerce page events (only called when WooCommerce is active). */
	private static function store_events() {
		$events = array();

		// Session-queued events (add/remove cart from previous request).
		foreach ( DXPS_Helpers::flush_queue() as $q ) $events[] = $q;

		if ( is_product() ) {
			$item = DXPS_Helpers::product_item( wc_get_product( get_the_ID() ) );
			if ( $item ) $events[] = array( 'name' => 'view_item', 'params' => array(
				'items' => array( $item ), 'value' => $item['price'], 'content_type' => $item['content_type'],
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
		return $events;
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
		$key = isset( $_GET['key'] ) && is_string( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
		if ( ! $key || ! hash_equals( (string) $order->get_order_key(), $key ) ) return null;
		if ( $order->get_customer_id() && (int) $order->get_customer_id() !== get_current_user_id() ) return null;
		if ( ! DXPS_Helpers::marketing_allowed() || in_array( $order->get_status(), array( 'failed','cancelled','refunded' ), true ) ) return null;
		if ( DXPS_Helpers::sensitive_context( '', array( 'items'=>DXPS_Helpers::order_items( $order ), 'coupons'=>$order->get_coupon_codes() ) ) ) return null;
		if ( 'thankyou' === DXPS_Helpers::opt( 'purchase_trigger', 'status' ) ) DXPS_Delivery::queue( $order_id );

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
				$params['_ec'] = DXPS_Helpers::google_user_from_order( $order );
			}
		}

		// Meta + GA4 purchase browser-side only in thankyou mode.
		$params['_scope'] = ( 'thankyou' === DXPS_Helpers::opt( 'purchase_trigger', 'status' ) ) ? 'all' : 'gads_only';

		return array( 'name' => 'purchase', 'params' => $params, 'event_id' => $event_id );
	}

	/* ---------- Cart hooks ---------- */

	/** dxps-front.js reads data-dxps-price on added_to_cart; without it AJAX adds were sent with no value. */
	public static function cart_events_ajax() {
		$origin = $_SERVER['HTTP_ORIGIN'] ?? ( $_SERVER['HTTP_REFERER'] ?? '' );
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || wp_parse_url( $origin, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) wp_send_json_error( array(), 403 );
		nocache_headers();
		if ( ! DXPS_Helpers::marketing_allowed() || self::off() ) { DXPS_Helpers::flush_queue(); wp_send_json_success( array() ); }
		wp_send_json_success( DXPS_Helpers::flush_queue() );
	}

	public static function variation_data( $data, $product, $variation ) {
		$data['dxps_item'] = DXPS_Helpers::product_item( $variation );
		return $data;
	}

	public static function loop_price_attr( $args, $product ) {
		if ( $product instanceof WC_Product ) {
			$args['attributes']['data-dxps-id'] = DXPS_Helpers::pid( $product );
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
		$order->update_meta_data( '_dxps_sid', DXPS_Helpers::ga_session_id() );
		$order->update_meta_data( '_dxps_ua',  isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ), 0, 500 ) : '' );
		$order->update_meta_data( '_dxps_ip',  DXPS_Helpers::client_ip() );
		if ( ! empty( $_COOKIE[ DXPS_Helpers::UTM_COOKIE ] ) ) {
			$order->update_meta_data( '_dxps_utm', sanitize_text_field( wp_unslash( $_COOKIE[ DXPS_Helpers::UTM_COOKIE ] ) ) );
		}
	}
}
