<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Order-only jobs. Queue stores IDs, never tokens or customer data. */
class DXPS_Delivery {
	public static function init() { add_action( 'dxps_purchase_job', array( __CLASS__, 'run' ), 10, 1 ); }
	public static function queue( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( '_dxps_purchase_sent' ) || '1' !== (string) $order->get_meta( '_dxps_marketing_allowed' ) || (int) $order->get_meta( '_dxps_delivery_attempts' ) >= 6 ) return false;
		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( 'dxps_purchase_job', array( (int) $order_id ), 'dx-pixel-suite' ) ) return true;
		if ( ! $order->get_meta( '_dxps_purchase_event_time' ) ) {
			$date = 'status' === DXPS_Helpers::opt( 'purchase_trigger', 'status' ) ? ( $order->get_date_completed() ?: $order->get_date_modified() ) : null;
			$order->update_meta_data( '_dxps_purchase_event_time', $date ? $date->getTimestamp() : time() ); $order->save();
		}
		DXPS_Jobs::schedule( 'dxps_purchase_job', array( (int) $order_id ) ); return true;
	}
	public static function run( $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) return;
		$lock = DXPS_Jobs::lock( 'purchase-' . (int) $order_id, 120 ); if ( ! $lock ) return;
		try {
			$order = wc_get_order( $order_id );
			if ( ! $order || $order->get_meta( '_dxps_purchase_sent' ) || '1' !== (string) $order->get_meta( '_dxps_marketing_allowed' ) ) return;
			if ( in_array( $order->get_status(), array( 'failed','cancelled','refunded' ), true ) ) return;
			if ( 'status' === DXPS_Helpers::opt( 'purchase_trigger', 'status' ) && $order->get_status() !== DXPS_Helpers::opt( 'purchase_status', 'completed' ) ) return;
			$meta_ready = DXPS_Helpers::on( 'meta_enabled' ) && DXPS_Helpers::opt( 'meta_pixel_id' ) && DXPS_Helpers::opt( 'meta_capi_token' ) && ! $order->get_meta( '_dxps_meta_purchase_sent' );
			$ga_ready = DXPS_Helpers::on( 'ga4_enabled' ) && DXPS_Helpers::ga4_server_allowed( 'purchase' ) && DXPS_Helpers::opt( 'ga4_id' ) && DXPS_Helpers::opt( 'ga4_api_secret' ) && ! $order->get_meta( '_dxps_ga4_purchase_sent' );
			if ( ! $meta_ready && ! $ga_ready ) return;
			$attempt = (int) $order->get_meta( '_dxps_delivery_attempts' ); if ( $attempt >= 6 ) return;
			$order->update_meta_data( '_dxps_delivery_attempts', ++$attempt ); $order->save();
			DXPS_Server::send_purchase( $order );
			if ( ! $order->get_meta( '_dxps_purchase_sent' ) && $attempt < 6 && '1' === (string) $order->get_meta( '_dxps_delivery_retryable' ) ) DXPS_Jobs::schedule( 'dxps_purchase_job', array( (int) $order_id ), 60 * pow( 2, $attempt - 1 ) );
		} finally { DXPS_Jobs::unlock( $lock ); }
	}
}
