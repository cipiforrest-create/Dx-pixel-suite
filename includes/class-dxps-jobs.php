<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Small shared scheduling/locking layer. Uses WooCommerce Action Scheduler when available. */
class DXPS_Jobs {
	public static function schedule( $hook, $args, $delay = 1 ) {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			// Do not use AS's unique flag: a currently running batch must enqueue its successor.
			return as_schedule_single_action( time() + $delay, $hook, $args, 'dx-pixel-suite' );
		}
		if ( ! wp_next_scheduled( $hook, $args ) ) return wp_schedule_single_event( time() + $delay, $hook, $args );
		return true;
	}
	public static function cancel( $hook, $args = null ) {
		if ( function_exists( 'as_unschedule_all_actions' ) ) as_unschedule_all_actions( $hook, $args, 'dx-pixel-suite' );
		if ( null === $args ) wp_unschedule_hook( $hook ); else wp_clear_scheduled_hook( $hook, $args );
	}
	/** Compare-and-delete expired locks: a stale worker must never release a newer lock. */
	public static function lock( $key, $ttl = 300 ) {
		global $wpdb;
		$key = 'dxps_lock_' . md5( $key );
		$token = ( time() + $ttl ) . ':' . wp_generate_uuid4();
		if ( add_option( $key, $token, '', false ) ) return array( $key, $token );
		$old = get_option( $key );
		if ( $old && (int) $old < time() ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s", $key, $old ) );
			wp_cache_delete( $key, 'options' );
			if ( add_option( $key, $token, '', false ) ) return array( $key, $token );
		}
		return false;
	}
	public static function unlock( $lock ) {
		global $wpdb;
		if ( ! $lock ) return;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s", $lock[0], $lock[1] ) );
		wp_cache_delete( $lock[0], 'options' );
	}
}
