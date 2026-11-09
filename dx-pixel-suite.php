<?php
/**
 * Plugin Name: DX Pixel Suite
 * Plugin URI:  https://digitalx.ba
 * Description: First-party marketing tracking for WooCommerce. Meta Pixel + Conversions API, GA4 (gtag + Measurement Protocol), Google Ads + Enhanced Conversions, GTM dataLayer mode, Consent Mode v2 (toggle), status-based Purchase for COD, WhatsApp/phone/email lead events. Built for eynna-hair.ba by Digital X.
 * Version:     1.6.1
 * Author:      Digital X
 * Text Domain: dx-pixel-suite
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'DXPS_VERSION', '1.6.1' );
define( 'DXPS_FILE', __FILE__ );
define( 'DXPS_DIR', plugin_dir_path( __FILE__ ) );
define( 'DXPS_URL', plugin_dir_url( __FILE__ ) );

require_once DXPS_DIR . 'includes/class-dxps-helpers.php';
require_once DXPS_DIR . 'includes/class-dxps-settings.php';
require_once DXPS_DIR . 'includes/class-dxps-tracking.php';
require_once DXPS_DIR . 'includes/class-dxps-server.php';

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
	);
	$existing = get_option( 'dxps_settings', array() );
	update_option( 'dxps_settings', array_merge( $defaults, (array) $existing ) );
} );

// Remove the nightly ACR job on deactivation so it does not keep firing while the plugin is off.
register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'dxps_cron_acr' );
} );

add_action( 'plugins_loaded', function () {
	DXPS_Settings::init();
	// Tracking and server hooks call WooCommerce functions directly (is_shop(), wc_get_order() ...).
	// Without WooCommerce they would fatal on every front-end request, so only the settings screen loads.
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			if ( current_user_can( 'manage_options' ) ) {
				echo '<div class="notice notice-error"><p><strong>DX Pixel Suite</strong> needs WooCommerce. Tracking is paused until WooCommerce is active.</p></div>';
			}
		} );
		return;
	}
	DXPS_Tracking::init();
	DXPS_Server::init();
} );
