<?php
/**
 * Plugin Name: DX Pixel Suite
 * Plugin URI:  https://digitalx.ba
 * Description: WooCommerce and content tracking with Meta Pixel/CAPI, GA4, Google Ads, consent controls and scheduled product catalogues for Google, Meta, Pinterest and Microsoft.
 * Version:     2.4.1
 * Author:      Digital X
 * Text Domain: dx-pixel-suite
 * Requires PHP: 7.4
 * Requires at least: 6.4
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'DXPS_VERSION', '2.4.1' );
define( 'DXPS_FILE', __FILE__ );
define( 'DXPS_DIR', plugin_dir_path( __FILE__ ) );
define( 'DXPS_URL', plugin_dir_url( __FILE__ ) );

require_once DXPS_DIR . 'includes/class-dxps-helpers.php';
require_once DXPS_DIR . 'includes/class-dxps-settings.php';
require_once DXPS_DIR . 'includes/class-dxps-tracking.php';
require_once DXPS_DIR . 'includes/class-dxps-server.php';
require_once DXPS_DIR . 'includes/class-dxps-email-identity.php';
require_once DXPS_DIR . 'includes/class-dxps-jobs.php';
require_once DXPS_DIR . 'includes/class-dxps-delivery.php';
require_once DXPS_DIR . 'includes/class-dxps-feed-product.php';
require_once DXPS_DIR . 'includes/class-dxps-feed-options.php';
require_once DXPS_DIR . 'includes/class-dxps-feed-editor.php';
require_once DXPS_DIR . 'includes/class-dxps-feeds.php';
require_once DXPS_DIR . 'includes/class-dxps-feeds-admin.php';
require_once DXPS_DIR . 'includes/class-dxps-product-fields.php';

add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
} );

register_activation_hook( __FILE__, function () {
	$defaults = array(
		// Meta
		'meta_enabled'        => 1,
		'meta_pixel_id'       => '',
		'meta_capi_token'     => '',
		'meta_test_code'      => '',
		// GA4
		'ga4_enabled'         => 1,
		'ga4_id'              => '',
		'ga4_api_secret'      => '',
		// Google Ads
		'gads_enabled'        => 0,
		'gads_conversion_id'  => '',   // AW-XXXXXXXXX
		'gads_purchase_label' => '',
		'gads_lead_label'     => '',
		'gads_enhanced'       => 1,
		// GTM
		'gtm_enabled'         => 0,
		'gtm_id'              => '',   // GTM-XXXXXXX
		'gtm_only'            => 0,    // if 1: only push dataLayer, no direct GA4/Ads tags (ignored while GTM is off)
		'engine_direct'       => 0,    // 1.6.0: run the GTM-equivalent enriched event engine in DX itself (needs GTM off)
		'ga4_server_purchase' => 0,    // 1.6.0: with engine_direct, also send purchase/refund via GA4 Measurement Protocol
		// Consent
		'consent_mode'        => 1,    // master toggle ON/OFF
		'consent_eea_default' => 'denied',
		// Behaviour
		'purchase_trigger'    => 'status',      // 'status' (COD-safe) or 'thankyou'
		'purchase_status'     => 'completed',
		'server_relay'        => 1,    // mirror browser events via CAPI / GA4 MP
		'track_leads'         => 1,    // WhatsApp / tel / mailto clicks
		'track_search'        => 1,
		'track_scroll'        => 1,    // PageScroll 25/50/75/90
		'track_time'          => 1,    // TimeOnPage
		'time_on_page'        => 30,   // seconds
		'track_clicks'        => 1,    // internal link clicks
		'auto_am'             => 1,    // Automatic Advanced Matching (scan forms for customer data)
		'excluded_coupons'    => 'alopeci',     // never sent to any platform
		'debug_log'           => 0,
		// 1.7.0 - every new feature is off until switched on
		'am_retention_days'     => 90,      // 2.3.0 default 90 days (browser cookie of hashes); 0 = 10 minutes; max 180
		'am_all_forms'          => 1,       // 2.3.0: on by default
		'url_identity'          => '',
		'phone_default_country' => 1,       // 2.3.0: on by default
		'fbp_fallback'          => 1,       // 2.3.0: on by default
		'track_form_leads'      => 0,
		'track_outbound'        => 0,
		'block_bots'            => 0,
		'exclude_roles'         => '',
		'purchase_value'        => 'total', // or 'net' (without tax and shipping)
		'meta_ldu'              => 0,
		'event_log'             => 0,
		// 1.8.1
		'content_types'         => 'post',      // comma list of post types: post,page,<custom>
		'content_service_paths' => '',
		'content_meta_event'    => 'auto',  // auto | standard (ViewContent) | custom (ContentView)
		'content_archives'      => 0,
		'content_engaged'       => 1,
		'content_read_scroll'   => 50,
		'content_read_time'     => 30,
		'geo_enrich'            => 'full',  // '' | country | full (default full since 2.4.0; needs Cloudflare)
		// 2.2.0
		'gads_merchant_id'      => '',      // Merchant Center ID: cart data on the Google Ads purchase conversion
		'gads_feed_country'     => '',
		'gads_feed_language'    => '',
		'gads_remarketing'      => 0,       // Google Ads dynamic remarketing events (opt-in)
		'ga4_page_titles'       => 0,       // send the real browser title to GA4 (opt-in)
		// 2.3.1
		'ip6_lookup'            => 1,       // browser looks up its IPv6 address after consent (Meta prefers IPv6)
		'ip6_url'               => 'https://api64.ipify.org',
		'param_builder'         => 1,       // Meta Parameter Builder in Facebook / Instagram in-app browsers
		// 2.4.0
		'email_identity'        => 1,       // signed recognition token in order-email links
	);
	$existing = get_option( 'dxps_settings', array() );
	update_option( 'dxps_settings', array_merge( $defaults, (array) $existing ) );
} );

// Remove the nightly ACR job on deactivation so it does not keep firing while the plugin is off.
register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'dxps_cron_acr' );
	wp_clear_scheduled_hook( 'dxps_feed_tick' );
	DXPS_Jobs::cancel( 'dxps_feed_batch' );
	DXPS_Jobs::cancel( 'dxps_purchase_job' );
	DXPS_Jobs::cancel( 'dxps_cron_acr_batch' );
} );

add_action( 'plugins_loaded', function () {
	// "Replace current with uploaded" does not run the activation hook, so settings added by later versions can be
	// missing from the database. Fill in the engagement-event switches once per version (missing keys only).
	if ( get_option( 'dxps_version' ) !== DXPS_VERSION ) {
		$o = get_option( 'dxps_settings', array() );
		if ( is_array( $o ) && $o ) {
			$changed = false;
			foreach ( DXPS_Helpers::FALLBACKS as $k => $v ) {
				if ( ! array_key_exists( $k, $o ) ) { $o[ $k ] = $v; $changed = true; }
			}
			// 2.3.0 (once): switch on the match-quality settings that were opt-in before. Only values still at their
			// old default are changed, so anything set by hand stays as it is.
			if ( version_compare( (string) get_option( 'dxps_version', '0' ), '2.3.0', '<' ) && ! get_option( 'dxps_emq_230' ) ) {
				if ( empty( $o['am_retention_days'] ) ) $o['am_retention_days'] = 90;
				foreach ( array( 'am_all_forms', 'phone_default_country', 'fbp_fallback' ) as $k ) {
					if ( empty( $o[ $k ] ) ) $o[ $k ] = 1;
				}
				if ( empty( $o['geo_enrich'] ) ) $o['geo_enrich'] = 'country';
				$changed = true;
				update_option( 'dxps_emq_230', time(), false );
			}
			// 2.3.1 (once): IPv6 lookup and Meta's Parameter Builder in in-app browsers, both after consent.
			if ( version_compare( (string) get_option( 'dxps_version', '0' ), '2.3.1', '<' ) && ! get_option( 'dxps_emq_231' ) ) {
				if ( ! array_key_exists( 'ip6_lookup', $o ) ) $o['ip6_lookup'] = 1;
				if ( ! array_key_exists( 'param_builder', $o ) ) $o['param_builder'] = 1;
				if ( empty( $o['ip6_url'] ) ) $o['ip6_url'] = 'https://api64.ipify.org';
				$changed = true;
				update_option( 'dxps_emq_231', time(), false );
			}
			// 2.4.0 (once): city / region / ZIP fill-in from Cloudflare (it never worked before 2.4.0) and order-email links.
			if ( version_compare( (string) get_option( 'dxps_version', '0' ), '2.4.0', '<' ) && ! get_option( 'dxps_emq_240' ) ) {
				if ( in_array( (string) ( $o['geo_enrich'] ?? '' ), array( '', 'country' ), true ) ) $o['geo_enrich'] = 'full';
				if ( ! array_key_exists( 'email_identity', $o ) ) $o['email_identity'] = 1;
				$changed = true;
				update_option( 'dxps_emq_240', time(), false );
			}
			if ( $changed ) update_option( 'dxps_settings', $o );
		}
		update_option( 'dxps_version', DXPS_VERSION, false );
	}
	add_action( 'update_option_dxps_settings', array( 'DXPS_Helpers', 'reset_options' ) );
	DXPS_Settings::init();
	// 1.8.1: WooCommerce is optional. Without it DX tracks page views, content, leads, forms and custom events.
	DXPS_Tracking::init();
	DXPS_Server::init();
	DXPS_Delivery::init();
	DXPS_Feeds::init();
	DXPS_Product_Fields::init();
	DXPS_Email_Identity::init();
} );
