<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class DXPS_Feeds_Admin {
	private static function redirect( $message, $error = false, $id = '' ) {
		set_transient( 'dxps_feed_notice_' . get_current_user_id(), array( 'message'=>$message,'error'=>$error ), 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=dx-pixel-suite&tab=feeds' . ( $id ? '&edit=' . rawurlencode( $id ) : '' ) ) ); exit;
	}
	public static function action() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Not authorized.', '', array( 'response'=>403 ) );
		check_admin_referer( 'dxps_feed' );
		$input = wp_unslash( $_POST ); $op = sanitize_key( $input['op'] ?? '' ); $id = sanitize_key( $input['id'] ?? '' );
		$cfg = DXPS_Feeds::config( $id );
		if('discover'===$op){delete_transient('dxps_policy_discovery');DXPS_Feed_Options::discover();self::redirect('Store policies and shipping zones scanned.',false,$id);}
		if ( 'build' === $op || 'continue' === $op ) {
			if ( ! $cfg ) self::redirect( 'Feed not found.', true );
			if ( 'build' === $op ) { $r = DXPS_Feeds::start( $id ); if ( is_wp_error( $r ) ) self::redirect( $r->get_error_message(), true, $id ); }
			$s = DXPS_Feeds::status( $id ); if ( ! empty( $s['run'] ) ) DXPS_Feeds::batch( $id, $s['run'] );
			self::redirect( 'A batch has run. Refresh for progress; scheduled workers will continue automatically.', false, $id );
		}
		if ( 'report' === $op && $cfg ) {
			nocache_headers(); header( 'Content-Type: application/json; charset=utf-8' ); header( 'Content-Disposition: attachment; filename="dxps-feed-' . $id . '-report.json"' );
			echo wp_json_encode( DXPS_Feeds::status( $id ), JSON_PRETTY_PRINT ); exit;
		}
		$registry = DXPS_Jobs::lock( 'feed-registry' ); if ( ! $registry ) self::redirect( 'Another feed setting is being saved. Try again.', true );
		$message = ''; $error = false; $lock = null;
		try {
			$all = DXPS_Feeds::all(); $cfg = $all[$id] ?? null;
			if ( $cfg ) { $lock = DXPS_Jobs::lock( 'feed-' . $id, 600 ); if ( ! $lock ) throw new RuntimeException( 'A worker is writing this feed. Try again shortly.' ); }
			if ( 'delete' === $op && $cfg ) {
				DXPS_Jobs::cancel( 'dxps_feed_batch', array( $id, DXPS_Feeds::status( $id )['run'] ?? '' ) );
				DXPS_Feeds::remove_files( $cfg ); unset( $all[$id] ); delete_option( 'dxps_feed_status_' . $id ); $message = 'Feed deleted; its URL no longer works.'; $id = '';
			} elseif ( 'cancel' === $op && $cfg ) {
				$s = DXPS_Feeds::status( $id ); $s['state']='cancelled'; update_option( 'dxps_feed_status_' . $id, $s, false ); DXPS_Jobs::cancel( 'dxps_feed_batch', array( $id, $s['run'] ?? '' ) ); DXPS_Feeds::remove_files( $cfg, true ); $message='Build cancelled; the last published feed is retained.';
			} elseif ( 'save' === $op ) {
				$new = DXPS_Feeds::sanitize( $input ); if ( is_wp_error( $new ) ) throw new RuntimeException( $new->get_error_message() );
				if ( ! $cfg && count( $all ) >= 25 ) throw new RuntimeException( 'Maximum 25 feeds. Remove an unused feed first.' );
				if ( $cfg && 'running' === ( DXPS_Feeds::status( $id )['state'] ?? '' ) ) throw new RuntimeException( 'Cancel the current build before editing its settings.' );
				$id = $cfg ? $id : substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 );
				$new['id']=$id; $new['token']=$cfg ? $cfg['token'] : bin2hex( random_bytes( 16 ) );
				$all[$id]=$new; $message='Feed saved. Select Build to generate and validate it.';
			} else throw new RuntimeException( 'Unknown action or feed.' );
			update_option( 'dxps_feeds', $all, false );
		} catch ( Throwable $e ) { $message=$e->getMessage(); $error=true; }
		finally { DXPS_Jobs::unlock( $lock ); DXPS_Jobs::unlock( $registry ); }
		self::redirect( $message, $error, $id );
	}
	private static function form_start( $id = '' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dxps_feed"><input type="hidden" name="id" value="' . esc_attr( $id ) . '">'; wp_nonce_field( 'dxps_feed' );
	}
	private static function field( $key, $label, $value, $description = '' ) {
		echo '<tr><th><label for="dxf-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><input class="regular-text" id="dxf-' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">';
		if ( $description ) echo '<p class="description">' . esc_html( $description ) . '</p>'; echo '</td></tr>';
	}
	private static function select( $key, $label, $value, $options ) {
		echo '<tr><th><label for="dxf-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><select id="dxf-' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '">';
		foreach ( $options as $k=>$v ) echo '<option value="' . esc_attr( $k ) . '" ' . selected( $k, $value, false ) . '>' . esc_html( $v ) . '</option>'; echo '</select></td></tr>';
	}
	public static function assets() {
		if(('dx-pixel-suite'!==($_GET['page']??''))||('feeds'!==($_GET['tab']??''))||!current_user_can('manage_options'))return;
		wp_enqueue_style('dxps-feeds-admin',DXPS_URL.'assets/css/dxps-feeds.css',array(),DXPS_VERSION);
		wp_enqueue_script('dxps-feeds-admin',DXPS_URL.'assets/js/dxps-feeds-admin.js',array(),DXPS_VERSION,true);
		wp_localize_script('dxps-feeds-admin','dxpsFeedAdmin',array('ajax'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('dxps_feed_progress')));
	}
	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) return;

		$notice = get_transient( 'dxps_feed_notice_' . get_current_user_id() );
		if ( $notice ) { delete_transient( 'dxps_feed_notice_' . get_current_user_id() ); echo '<div class="notice notice-' . ( $notice['error'] ? 'error' : 'success' ) . '"><p>' . esc_html( $notice['message'] ) . '</p></div>'; }
		echo '<h2>Product catalogues</h2><p>Create scheduled product data sources for Google, Meta, Pinterest and Microsoft. Product IDs always follow Events &amp; Parameters → Product ID format (<strong>' . esc_html( DXPS_Helpers::opt( 'product_id_format', 'id' ) ) . '</strong>).</p>';
		if ( ! function_exists( 'wc_get_product' ) ) { echo '<div class="notice notice-warning inline"><p>Activate WooCommerce to generate product catalogues. Tracking for other pages remains available.</p></div>'; return; }
		echo '<p>Invalid offers are excluded with a report. This checks file data; account eligibility, policies, shipping, returns, tax, image access and landing-page accuracy are reviewed by each platform.</p>';
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) echo '<div class="notice notice-warning inline"><p>Visitor-triggered WP-Cron is disabled; a server cron may already be configured. While this page is open, DX will advance running builds. Recurring unattended updates still need a cron/Action Scheduler runner.</p></div>';
		$all = DXPS_Feeds::all();
		if ( $all ) {
			echo '<table class="widefat striped"><thead><tr><th>Feed</th><th>Status</th><th>Last published</th><th>Actions</th></tr></thead><tbody>';
			foreach ( $all as $id=>$c ) {
				$s = DXPS_Feeds::status( $id );
				echo '<tr data-dxps-feed="' . esc_attr($id) . '" data-running="' . ('running'===($s['state']??'')?'1':'0') . '"><td><a href="' . esc_url( admin_url( 'admin.php?page=dx-pixel-suite&tab=feeds&edit=' . $id ) ) . '">' . esc_html( $c['name'] ) . '</a><br>' . esc_html( DXPS_Feeds::PROFILES[$c['platform']] . ' · ' . strtoupper( $c['format'] ) . ' · ' . $c['schedule'] ) . '</td><td>' . esc_html( $s['state'] ?? 'not generated' ) . '<br>' . esc_html( ( $s['processed'] ?? 0 ) . ' scanned / ' . ( $s['rows'] ?? 0 ) . ' valid / ' . ( $s['skipped'] ?? 0 ) . ' skipped' ) . '<br>' . esc_html( ( $s['errors'] ?? 0 ) . ' errors / ' . ( $s['warnings'] ?? 0 ) . ' warnings' ) . '</td><td>';
				if ( ! empty( $s['published'] ) ) {
					echo esc_html( wp_date( 'Y-m-d H:i T', $s['published'] ) . ' · ' . $s['published_rows'] . ' offers' );
					$dir = DXPS_Feeds::directory();
					if ( ! is_wp_error( $dir ) ) echo '<br><input aria-label="Published feed URL" class="large-text" readonly onclick="this.select()" value="' . esc_attr( $dir['url'] . '/' . $s['published_file'] ) . '">';
				}
				echo '</td><td>'; self::form_start( $id );
				if ( 'running' === ( $s['state'] ?? '' ) ) echo '<button class="button" name="op" value="continue">Continue</button> <button class="button" name="op" value="cancel">Cancel build</button>';
				else echo '<button class="button button-primary" name="op" value="build">Build</button>';
				echo ' <button class="button" name="op" value="report">Report JSON</button> <button class="button" name="op" value="delete" onclick="return confirm(\'Delete this feed and its published files?\')">Delete</button></form></td></tr>';
			}
			echo '</tbody></table>';
		}
		$edit=sanitize_key($_GET['edit']??'');DXPS_Feed_Editor::render($edit,$all[$edit]??DXPS_Feeds::defaults());

		echo '<h2>Connect a catalogue</h2><ol><li>Build the feed and fix the validation report. Copy the published URL above.</li><li>Google Merchant Center: add a product data source using a file URL and scheduled fetch. Set country/language, verify your website, and configure shipping, tax and returns in Merchant Center.</li><li>Meta Commerce Manager: open your catalogue → Data sources → Data feed, use the URL and choose a schedule. Connect the matching Pixel/Dataset.</li><li>Pinterest and Microsoft: add a scheduled catalogue/product feed using the same URL workflow and the correct platform profile.</li><li>Check platform processing diagnostics after the first fetch. Compare event content_ids/item_id against feed IDs; rebuild every feed after changing the shared ID format.</li></ol><p>Each feed uses your store currency and current content language. Currency conversion, translated country feeds, marketplace-specific policies and live account approval require separate configuration. CSV/TSV files preserve exact product values: import them as text if opening in a spreadsheet.</p><p>Public URLs contain product data only. No API token is needed for scheduled fetching. Refresh this page to see worker progress.</p>';
	}
}
