<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Resumable product exports. Last successful file stays available during every rebuild. */
class DXPS_Feeds {
	const PROFILES = array( 'google'=>'Google Merchant Center', 'meta'=>'Meta / Facebook', 'pinterest'=>'Pinterest', 'microsoft'=>'Microsoft Merchant Center', 'generic'=>'Generic export' );
	const FORMATS = array( 'google'=>array( 'xml','tsv' ), 'meta'=>array( 'xml','csv','tsv' ), 'pinterest'=>array( 'xml','csv','tsv' ), 'microsoft'=>array( 'xml','tsv' ), 'generic'=>array( 'xml','csv','tsv','json' ) );
	const INTERVALS = array( 'hourly'=>3600, 'twicedaily'=>43200, 'daily'=>86400, 'manual'=>0 );

	public static function init() {
		add_action( 'dxps_feed_batch', array( __CLASS__, 'batch' ), 10, 2 );
		add_action( 'dxps_feed_tick', array( __CLASS__, 'tick' ) );
		add_action('wp_ajax_dxps_feed_progress',array('DXPS_Feed_Editor','progress'));
		add_action('admin_enqueue_scripts',array('DXPS_Feeds_Admin','assets'));
		add_action( 'admin_post_dxps_feed', array( 'DXPS_Feeds_Admin', 'action' ) );
		if ( ! wp_next_scheduled( 'dxps_feed_tick' ) ) wp_schedule_event( time() + 300, 'hourly', 'dxps_feed_tick' );
		// 2.2.0: a price, sale, stock or visibility change marks the catalogues stale, so a scheduled feed is rebuilt
		// at the next hourly tick instead of advertising sold-out or wrongly priced products until its next full run.
		add_action( 'woocommerce_product_object_updated_props', array( __CLASS__, 'product_changed' ), 10, 2 );
		foreach ( array( 'woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status', 'woocommerce_new_product', 'woocommerce_delete_product', 'woocommerce_trash_product' ) as $hook ) add_action( $hook, array( __CLASS__, 'touch' ), 10, 0 );
	}
	public static function product_changed( $product, $props ) {
		if ( array_intersect( (array) $props, array( 'price','regular_price','sale_price','date_on_sale_from','date_on_sale_to','stock_status','stock_quantity','manage_stock','backorders','status','catalog_visibility','name','description','short_description','image_id','gallery_image_ids','category_ids','sku' ) ) ) self::touch();
	}
	/** One small write per request, however many products changed. */
	public static function touch() {
		static $done = false;
		if ( $done || ! get_option( 'dxps_feeds' ) ) return;
		$done = true;
		update_option( 'dxps_feeds_dirty', time(), false );
	}
	public static function all() { $all=(array)get_option('dxps_feeds',array()); foreach($all as &$cfg)$cfg=array_merge(DXPS_Feed_Options::DEFAULTS,$cfg); return $all; }
	public static function config( $id ) { $all = self::all(); return $all[$id] ?? null; }
	public static function defaults() {
		return array( 'name'=>'', 'platform'=>'google', 'format'=>'xml', 'schedule'=>'daily', 'brand'=>'', 'condition'=>'new', 'google_category'=>'', 'tax_mode'=>'display', 'apparel'=>0, 'include_children'=>1, 'in_stock_only'=>0, 'include_categories'=>array(), 'exclude_categories'=>array(), 'exclude_ids'=>array(), 'mapping'=>array() );
	}
	public static function sanitize( $input ) {
		$out = self::defaults();
		foreach ( array( 'name','brand','google_category' ) as $k ) $out[$k] = DXPS_Feed_Product::text( $input[$k] ?? '', 150 );
		foreach ( array( 'platform'=>array_keys( self::PROFILES ), 'schedule'=>array_keys( self::INTERVALS ), 'condition'=>array( 'new','used','refurbished' ), 'tax_mode'=>array( 'display','including','excluding' ) ) as $k=>$values ) if ( isset( $input[$k] ) && in_array( $input[$k], $values, true ) ) $out[$k] = $input[$k];
		if ( ! in_array( $input['format'] ?? '', self::FORMATS[$out['platform']], true ) ) return new WP_Error( 'format', 'This format is not supported by the selected platform. Google and Microsoft: XML or TSV; Meta and Pinterest: XML, CSV or TSV; JSON: generic export only.' );
		$out['format'] = $input['format'];
		foreach ( array( 'apparel','in_stock_only' ) as $k ) $out[$k] = empty( $input[$k] ) ? 0 : 1;
		foreach ( array( 'include_categories','exclude_categories','exclude_ids' ) as $k ) {
			$ids = is_array( $input[$k] ?? null ) ? $input[$k] : preg_split( '/[\s,]+/', $input[$k] ?? '' );
			$out[$k] = array_slice( array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) ), 0, 500 );
		}
		if(isset($input['mapping_rows'])) {
            if(!is_array($input['mapping_rows']))return new WP_Error('mapping','Invalid mapping rows.');
            $input['mapping']=array();
            foreach($input['mapping_rows'] as $field=>$row){
                if(!is_array($row)||!is_string($row['type']??'')||!is_string($row['key']??''))return new WP_Error('mapping','Invalid mapping row.');
                if(($row['type']??'')!==''){
                    if(trim($row['key']??'')==='')return new WP_Error('mapping','Enter a source key or value for '.$field.'.');
                    $input['mapping'][$field]=$row['type'].':'.$row['key'];
                }
            }
        }
        $mapping = $input['mapping'] ?? array();
		if ( is_string( $mapping ) ) { if ( strlen( $mapping ) > 20000 ) return new WP_Error( 'mapping', 'Mapping is too large.' ); $mapping = json_decode( $mapping ?: '{}', true ); }
		if ( ! is_array( $mapping ) ) return new WP_Error( 'mapping', 'Mappings must be a JSON object.' );
		foreach ( $mapping as $field=>$source ) {
			if ( ! in_array( $field, DXPS_Feed_Product::MAPPABLE, true ) || ! is_string( $source ) || ! preg_match( '/^(meta|attribute|taxonomy|literal):(.+)$/s', $source, $m ) ) return new WP_Error( 'mapping', 'Invalid mapping: ' . sanitize_key( $field ) . '. Use meta:, attribute:, taxonomy: or literal: sources.' );
			if ( 'literal' !== $m[1] && ! preg_match( '/^[a-zA-Z0-9_.-]{1,100}$/', $m[2] ) ) return new WP_Error( 'mapping', 'Invalid source key for ' . $field );
			$out['mapping'][$field] = $m[1] . ':' . DXPS_Feed_Product::text( $m[2], 5000 );
		}
		if ( ! $out['name'] ) return new WP_Error( 'name', 'Give this feed a name.' );
		return DXPS_Feed_Options::sanitize($input,$out);
	}
	public static function directory() {
		$u = wp_upload_dir();
		if ( ! empty( $u['error'] ) ) return new WP_Error( 'uploads', 'WordPress uploads directory is unavailable.' );
		$path = trailingslashit( $u['basedir'] ) . 'dxps-feeds';
		if ( ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) return new WP_Error( 'directory', 'Cannot create the feed directory.' );
		if ( ! file_exists( $path . '/index.php' ) ) file_put_contents( $path . '/index.php', "<?php // No directory index.\n" );
		return array( 'path'=>$path, 'url'=>trailingslashit( $u['baseurl'] ) . 'dxps-feeds' );
	}
	public static function filename( $cfg ) {
		$ext = 'tsv' === $cfg['format'] && in_array( $cfg['platform'], array( 'google','microsoft' ), true ) ? 'txt' : $cfg['format'];
		return 'catalog-' . $cfg['id'] . '-' . $cfg['token'] . '.' . $ext;
	}
	public static function url( $cfg ) {
		$dir = self::directory();
		return is_wp_error( $dir ) ? '' : $dir['url'] . '/' . self::filename( $cfg );
	}
	public static function status( $id ) { return (array) get_option( 'dxps_feed_status_' . $id, array() ); }
	public static function tick() {
		$dirty = (int) get_option( 'dxps_feeds_dirty', 0 );
		foreach ( self::all() as $id=>$cfg ) {
			$s = self::status( $id );
			if ( 'running' === ( $s['state'] ?? '' ) ) {
				if ( ( $s['updated'] ?? 0 ) < time() - 900 ) self::batch( $id, $s['run'] );
				continue;
			}
			$interval = self::INTERVALS[$cfg['schedule']] ?? 0;
			$started = (int) ( $s['started'] ?? 0 );
			if ( ! $interval ) continue; // manual feeds are never rebuilt automatically
			if ( $started + $interval <= time() ) self::start( $id );
			elseif ( $dirty > $started && $started + HOUR_IN_SECONDS <= time() && apply_filters( 'dxps_feed_rebuild_on_change', true, $id, $cfg ) ) self::start( $id );
		}
	}
	public static function start( $id ) {
		if ( ! function_exists( 'wc_get_product' ) ) return new WP_Error( 'woocommerce', 'WooCommerce must be active.' );
		$cfg = self::config( $id );
		if ( ! $cfg ) return new WP_Error( 'feed', 'Feed not found.' );
		$lock = DXPS_Jobs::lock( 'feed-' . $id );
		if ( ! $lock ) return new WP_Error( 'busy', 'A feed worker is already running. Try again shortly.' );
		try {
			$previous = self::status( $id );
			if ( 'running' === ( $previous['state'] ?? '' ) ) return new WP_Error( 'busy', 'This feed is already queued. Use Continue if cron is unavailable.' );
			$dir = self::directory(); if ( is_wp_error( $dir ) ) return $dir;
			self::remove_files( $cfg, true );
			$run = str_replace( '-', '', wp_generate_uuid4() );
			$temp = $dir['path'] . '/' . self::filename( $cfg ) . '.' . $run . '.part';
			$fp = fopen( $temp, 'wb' ); if ( ! $fp ) return new WP_Error( 'write', 'Cannot write the feed file.' );
			try { self::header( $fp, $cfg ); if ( ! fflush( $fp ) ) throw new RuntimeException( 'flush_failed' ); $bytes = ftell( $fp ); } finally { fclose( $fp ); }
			$s = array( 'state'=>'running','run'=>$run,'started'=>time(),'updated'=>time(),'cursor'=>0,'bytes'=>$bytes,'processed'=>0,'rows'=>0,'skipped'=>0,'errors'=>0,'warnings'=>0,'issues'=>array(),'published'=>$previous['published'] ?? 0,'published_rows'=>$previous['published_rows'] ?? 0,'published_file'=>$previous['published_file'] ?? '', 'config_hash'=>hash( 'sha256', wp_json_encode( $cfg ) . DXPS_Helpers::opt( 'product_id_format', 'id' ) ) );
			update_option( 'dxps_feed_status_' . $id, $s, false );
			DXPS_Jobs::schedule( 'dxps_feed_batch', array( $id, $run ) );
			return true;
		} finally { DXPS_Jobs::unlock( $lock ); }
	}
	private static function write( $fp, $text ) {
		$len = strlen( $text ); $offset = 0;
		while ( $offset < $len ) { $n = fwrite( $fp, substr( $text, $offset ) ); if ( false === $n || 0 === $n ) throw new RuntimeException( 'disk_write_failed' ); $offset += $n; }
	}
	private static function xml( $v ) { return htmlspecialchars( (string) $v, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
	private static function header( $fp, $cfg ) {
		if ( 'xml' === $cfg['format'] ) self::write( $fp, '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0"><channel><title>' . self::xml( $cfg['name'] ) . '</title><link>' . self::xml( home_url( '/' ) ) . '</link><description>WooCommerce product catalogue</description>' . "\n" );
		elseif ( 'json' === $cfg['format'] ) self::write( $fp, '[' );
		elseif ( false === fputcsv( $fp, self::columns($cfg), 'tsv' === $cfg['format'] ? "\t" : ',', '"', '' ) ) throw new RuntimeException( 'disk_write_failed' );
	}
	public static function fields($cfg){
		$drop=array();
		if(!in_array($cfg['platform'],array('google','generic'),true))$drop[]='return_policy_label';
		if(!in_array($cfg['platform'],array('meta','generic'),true))$drop[]='quantity_to_sell_on_facebook';
		return array_values(array_diff(DXPS_Feed_Product::FIELDS,$drop));
	}
	public static function columns($cfg){$columns=self::fields($cfg);foreach($columns as &$key)if('shipping'===$key&&in_array($cfg['platform'],array('google','generic','microsoft'),true))$key='shipping(country:region:service:price'.(in_array($cfg['platform'],array('google','generic'),true)?':min_handling_time:max_handling_time:min_transit_time:max_transit_time':'').')';return $columns;}
	public static function write_row( $fp, $row, $cfg, $first = false ) {
		// Meta has no "backorder" value: an item that can be ordered but is not in stock is "available for order".
		if ( 'meta' === $cfg['platform'] && 'backorder' === $row['availability'] ) $row['availability'] = 'available for order';
		if ( in_array( $cfg['platform'], array( 'meta','pinterest' ), true ) ) $row['availability'] = str_replace( '_', ' ', $row['availability'] );
		if ( in_array( $row['identifier_exists'] ?? '', array( 'false','0' ), true ) ) $row['identifier_exists'] = 'no';
		if ( in_array( $row['identifier_exists'] ?? '', array( 'true','1' ), true ) ) $row['identifier_exists'] = 'yes';
		if ( 'xml' === $cfg['format'] ) {
			$out = "<item>\n";
			foreach(self::fields($cfg) as $key){
			 if('shipping'===$key){foreach(($row[$key]??array()) as $rate){$out.='<g:shipping>';foreach($rate as $sub=>$v){if(!in_array($sub,array('country','region','service','price','min_handling_time','max_handling_time','min_transit_time','max_transit_time'),true))continue;if(!in_array($cfg['platform'],array('google','generic'),true)&&strpos($sub,'_time')!==false)continue;if(''!==(string)$v)$out.='<g:'.$sub.'>'.self::xml($v).'</g:'.$sub.'>';}$out.="</g:shipping>\n";}continue;}
			 foreach((array)($row[$key]??array()) as $v)if(''!==(string)$v)$out.='<g:'.$key.'>'.self::xml($v).'</g:'.$key.">\n";
			}

			self::write( $fp, $out . "</item>\n" );
		} elseif ( 'json' === $cfg['format'] ) {
			$json = wp_json_encode( $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( false === $json ) throw new RuntimeException( 'json_encoding_failed' );
			self::write( $fp, ( $first ? '' : ",\n" ) . $json );
		} else {
			$values = array();
			foreach ( self::fields($cfg) as $key ) {
				$value = $row[$key] ?? '';
				if('shipping'===$key){$rates=array();foreach((array)$value as $rate){$keys=in_array($cfg['platform'],array('google','generic'),true)?array('country','region','service','price','min_handling_time','max_handling_time','min_transit_time','max_transit_time'):array('country','region','service','price');$parts=array();foreach($keys as $k)$parts[]=$rate[$k]??'';$rates[]=implode(':',$parts);}$value=implode(',',$rates);}
				if ( is_array( $value ) ) $value = implode( ',', $value );
				// Preserve catalogue IDs exactly. Feed files are machine imports, not spreadsheet documents.
				$values[] = str_replace( array( "\r","\n","\t" ), ' ', (string) $value );
			}
			if ( false === fputcsv( $fp, $values, 'tsv' === $cfg['format'] ? "\t" : ',', '"', '' ) ) throw new RuntimeException( 'disk_write_failed' );
		}
	}
	public static function batch( $id, $run ) {
		global $wpdb;
		$cfg = self::config( $id ); $s = self::status( $id );
		if ( ! $cfg || ! function_exists( 'wc_get_product' ) || 'running' !== ( $s['state'] ?? '' ) || ( $s['run'] ?? '' ) !== $run ) return;
		$lock = DXPS_Jobs::lock( 'feed-' . $id, 600 ); if ( ! $lock ) return;
		$fp = null;
		try {
			// Reload after acquiring the lock: another worker may have committed while this one waited.
			$s = self::status( $id );
			if ( 'running' !== $s['state'] || $s['run'] !== $run ) return;
			if ( $s['config_hash'] !== hash( 'sha256', wp_json_encode( $cfg ) . DXPS_Helpers::opt( 'product_id_format', 'id' ) ) ) throw new RuntimeException( 'settings_changed_rebuild_required' );
			$dir = self::directory(); if ( is_wp_error( $dir ) ) throw new RuntimeException( 'uploads_unavailable' );
			$temp = $dir['path'] . '/' . self::filename( $cfg ) . '.' . $run . '.part';
			$fp = fopen( $temp, 'c+b' ); if ( ! $fp ) throw new RuntimeException( 'file_open_failed' );
			if ( fstat( $fp )['size'] < $s['bytes'] || ! ftruncate( $fp, $s['bytes'] ) || 0 !== fseek( $fp, $s['bytes'] ) ) throw new RuntimeException( 'checkpoint_file_invalid' );
			// Products use the WordPress posts data store; orders/HPOS are never queried here.
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type IN ('product','product_variation') AND post_status='publish' ORDER BY ID ASC LIMIT 100", $s['cursor'] ) );
			if ( $wpdb->last_error ) throw new RuntimeException( 'product_query_failed' );
			$deadline = microtime( true ) + 15;
			foreach ( $ids as $pid ) {
				$result = DXPS_Feed_Product::build( wc_get_product( $pid ), $cfg );
				$s['processed']++; $s['cursor'] = (int) $pid;
				if ( isset( $result['skip'] ) ) $s['skipped']++;
				else {
					foreach ( array( 'errors','warnings' ) as $severity ) {
						$s[$severity] += count( $result[$severity] );
						foreach($result[$severity] as $code)$s['issue_counts'][$code]=($s['issue_counts'][$code]??0)+1;
						foreach ( $result[$severity] as $code ) if ( count( $s['issues'] ) < 100 ) $s['issues'][] = array( 'product_id'=>(int) $pid,'severity'=>$severity,'code'=>$code );
					}
					if ( $result['errors'] ) $s['skipped']++;
					else { self::write_row( $fp, $result['row'], $cfg, 0 === $s['rows'] ); $s['rows']++; }
				}
				if ( microtime( true ) >= $deadline ) break;
			}
			if ( ! fflush( $fp ) ) throw new RuntimeException( 'flush_failed' );
			$s['bytes'] = ftell( $fp ); $s['updated'] = time();
			if ( ! $ids ) {
				// Never replace a working catalogue with zero offers after a bad mapping/filter edit.
				if ( ! $s['rows'] ) throw new RuntimeException( 'no_valid_products_previous_feed_preserved' );
				if ( 'xml' === $cfg['format'] ) self::write( $fp, "</channel></rss>\n" );
				if ( 'json' === $cfg['format'] ) self::write( $fp, "\n]\n" );
				if ( ! fflush( $fp ) ) throw new RuntimeException( 'flush_failed' );
				fclose( $fp ); $fp = null;
				$final = $dir['path'] . '/' . self::filename( $cfg );
				if ( ! rename( $temp, $final ) ) throw new RuntimeException( 'atomic_publish_failed' );
				$s['state'] = 'ready'; $s['published'] = time(); $s['published_rows'] = $s['rows']; $s['published_file'] = self::filename( $cfg ); $s['sha256'] = hash_file( 'sha256', $final );
			}
			update_option( 'dxps_feed_status_' . $id, $s, false );
			if ( 'running' === $s['state'] ) DXPS_Jobs::schedule( 'dxps_feed_batch', array( $id, $run ), 5 );
		} catch ( Throwable $e ) {
			$s['state'] = 'failed'; $s['failure'] = 'generation_failed';
			if ( preg_match( '/^[a-z_]+$/', $e->getMessage() ) ) $s['failure'] = $e->getMessage();
			$s['updated'] = time(); update_option( 'dxps_feed_status_' . $id, $s, false );
		} finally { if ( is_resource( $fp ) ) fclose( $fp ); DXPS_Jobs::unlock( $lock ); }
	}
	public static function remove_files( $cfg, $parts_only = false ) {
		$dir = self::directory(); if ( is_wp_error( $dir ) ) return;
		$prefix = $dir['path'] . '/catalog-' . $cfg['id'] . '-' . $cfg['token'] . '.';
		foreach ( glob( $prefix . '*' ) ?: array() as $file ) if ( is_file( $file ) && ( ! $parts_only || '.part' === substr( $file, -5 ) ) ) wp_delete_file( $file );
	}
}
