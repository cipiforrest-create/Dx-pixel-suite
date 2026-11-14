<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** WooCommerce -> catalogue row. No customer data or executable mapping expressions. */
class DXPS_Feed_Product {
	const FIELDS = array( 'id','title','description','link','image_link','additional_image_link','availability','availability_date','price','sale_price','sale_price_effective_date','condition','brand','gtin','mpn','identifier_exists','item_group_id','google_product_category','product_type','color','size','gender','age_group','material','pattern','size_type','size_system','shipping','return_policy_label','quantity_to_sell_on_facebook','shipping_weight','shipping_label','tax_category','adult','multipack','is_bundle','custom_label_0','custom_label_1','custom_label_2','custom_label_3','custom_label_4' );
	const MAPPABLE = array( 'title','description','image_link','brand','gtin','mpn','identifier_exists','google_product_category','product_type','color','size','gender','age_group','material','pattern','size_type','size_system','availability_date','shipping_label','tax_category','adult','multipack','is_bundle','custom_label_0','custom_label_1','custom_label_2','custom_label_3','custom_label_4' );

	/** Attribute slugs (with or without the pa_ prefix) read for each feed field, including common localised names. */
	const ATTRIBUTE_ALIASES = array(
		'color'    => array( 'color', 'colour', 'boja', 'boje', 'nijansa', 'farbe', 'couleur', 'kleur' ),
		'size'     => array( 'size', 'velicina', 'veličina', 'duzina', 'dužina', 'length', 'groesse', 'grosse', 'taille', 'maat' ),
		'material' => array( 'material', 'materijal', 'stoff', 'matiere' ),
		'pattern'  => array( 'pattern', 'uzorak', 'muster', 'motif' ),
	);

	private static function attribute( $p, $field ) {
		static $aliases = null;
		if ( null === $aliases ) $aliases = (array) apply_filters( 'dxps_feed_attribute_aliases', self::ATTRIBUTE_ALIASES );
		foreach ( (array) ( $aliases[ $field ] ?? array( $field ) ) as $slug ) {
			// A variation returns its own selected value and falls back to the parent's attribute.
			$v = $p->get_attribute( 'pa_' . $slug ) ?: $p->get_attribute( $slug );
			if ( is_string( $v ) && '' !== $v ) return $v;
		}
		return '';
	}

	/** Full path of the product's most specific category: "Hair extensions > Clip-in", not an unordered term list. */
	private static function category_path( $ids ) {
		static $cache = array();
		$best = array();
		foreach ( $ids as $id ) {
			if ( ! isset( $cache[ $id ] ) ) {
				$chain = array_reverse( get_ancestors( $id, 'product_cat', 'taxonomy' ) ); $chain[] = $id; $names = array();
				foreach ( $chain as $t ) { $term = get_term( $t, 'product_cat' ); if ( $term && ! is_wp_error( $term ) ) $names[] = $term->name; }
				$cache[ $id ] = $names;
			}
			if ( count( $cache[ $id ] ) > count( $best ) ) $best = $cache[ $id ];
		}
		return implode( ' > ', $best );
	}

	/**
	 * Segmentation labels for campaign structure and bidding (opt-in per feed): price tier, sale state, sales
	 * history, novelty and stock. Built from store facts only; a label set on the product always wins.
	 */
	private static function auto_labels( $p, $base, $cfg, $price ) {
		$tier = 'price-0+'; $prev = 0;
		foreach ( $cfg['price_tiers'] as $limit ) { if ( $price < $limit ) { $tier = 'price-' . $prev . '-' . $limit; break; } $prev = $limit; $tier = 'price-' . $limit . '+'; }
		$sales = (int) $base->get_total_sales();
		list( $top, $steady ) = array_pad( array_map( 'absint', (array) apply_filters( 'dxps_feed_sales_tiers', array( 30, 5 ) ) ), 2, 0 );
		$created = $base->get_date_created();
		$stock = $p->is_on_backorder( 1 ) ? 'backorder' : ( ! $p->is_in_stock() ? 'out-of-stock' : 'in-stock' );
		if ( 'in-stock' === $stock && $p->managing_stock() && null !== $p->get_stock_quantity() && $p->get_stock_quantity() <= max( 1, (int) wc_get_low_stock_amount( $p ) ) ) $stock = 'low-stock';
		return array(
			'custom_label_0' => $tier,
			'custom_label_1' => $p->is_on_sale() ? 'on-sale' : 'full-price',
			'custom_label_2' => $sales >= $top ? 'bestseller' : ( $sales >= $steady ? 'steady-seller' : ( $sales > 0 ? 'low-sales' : 'no-sales' ) ),
			'custom_label_3' => ( $created && $created->getTimestamp() > time() - 45 * DAY_IN_SECONDS ) ? 'new-arrival' : 'catalogue',
			'custom_label_4' => $stock,
		);
	}

	public static function text( $value, $limit = 5000 ) {
		if ( ! is_scalar( $value ) ) return '';
		$value = preg_replace('~<(?:br\b[^>]*|/(?:p|div|li|h[1-6]|tr|td|section))>~i',' ',(string)$value);
		$value = html_entity_decode( wp_strip_all_tags( strip_shortcodes( (string) $value ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$value = preg_replace( '/[^\P{C}\t\n\r]/u', '', wp_check_invalid_utf8( $value ) );
		$value = trim( preg_replace( '/\s+/u', ' ', $value ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $limit, 'UTF-8' ) : implode( '', array_slice( preg_split( '//u', $value, -1, PREG_SPLIT_NO_EMPTY ), 0, $limit ) );
	}

	public static function valid_url( $value ) {
		$p = wp_parse_url( $value );
		return is_array( $p ) && in_array( $p['scheme'] ?? '', array( 'http','https' ), true ) && ! empty( $p['host'] ) && empty( $p['user'] ) && empty( $p['pass'] ) && strlen( $value ) <= 2000;
	}

	/** GS1 check digit, preserving leading zeroes. */
	public static function valid_gtin( $gtin ) {
		if ( ! preg_match( '/^(?:\d{8}|\d{12}|\d{13}|\d{14})$/', $gtin ) || preg_match( '/^0+$/', $gtin ) ) return false;
		$sum = 0; $weight = 3;
		for ( $i = strlen( $gtin ) - 2; $i >= 0; $i-- ) { $sum += (int) $gtin[$i] * $weight; $weight = 4 - $weight; }
		return ( 10 - $sum % 10 ) % 10 === (int) substr( $gtin, -1 );
	}

	private static function meta( $p, $parent, $keys, $inherit = true ) {
		foreach ( $keys as $key ) {
			$v = '_global_unique_id' === $key && method_exists( $p, 'get_global_unique_id' ) ? $p->get_global_unique_id( 'edit' ) : $p->get_meta( $key, true );
			if ( is_scalar( $v ) && '' !== (string) $v ) return (string) $v;
		}
		if ( $inherit && $parent ) return self::meta( $parent, null, $keys );
		return '';
	}

	private static function mapped( $source, $p, $parent ) {
		list( $type, $key ) = array_pad( explode( ':', $source, 2 ), 2, '' );
		if ( 'literal' === $type ) return $key;
		if ( 'meta' === $type ) return self::meta( $p, $parent, array( $key ), ! in_array( $key, array( '_global_unique_id','_gtin','_wc_gla_gtin','wpfoof-gtin' ), true ) );
		if ( 'attribute' === $type ) return $p->get_attribute( $key ) ?: ( $parent ? $parent->get_attribute( $key ) : '' );
		if ( 'taxonomy' === $type && taxonomy_exists( $key ) ) {
			$terms = wc_get_product_terms( $parent ? $parent->get_id() : $p->get_id(), $key, array( 'fields'=>'names' ) );
			return is_wp_error( $terms ) ? '' : implode( ' > ', $terms );
		}
		return '';
	}

	public static function build( $p, $cfg ) {
		$cfg=array_merge(DXPS_Feed_Options::DEFAULTS,$cfg);
		$errors = array(); $warnings = array();
		if ( ! $p || 'publish' !== $p->get_status() || $p->is_type( array( 'variable','grouped' ) ) ) return array( 'skip'=>'not_a_published_offer' );
		$parent = $p->is_type( 'variation' ) ? wc_get_product( $p->get_parent_id() ) : null;
		$base = $parent ?: $p;
		if ( ( $p->is_type( 'variation' ) && ! $parent ) || 'publish' !== $base->get_status() || 'hidden' === $base->get_catalog_visibility() || post_password_required( $base->get_id() ) ) return array( 'skip'=>'hidden_or_private' );
		if ( $p->is_type( 'variation' ) && ! $p->variation_is_visible() ) return array( 'skip'=>'disabled_variation' );
		if ( ! empty( $cfg['in_stock_only'] ) && ! $p->is_in_stock() ) return array( 'skip'=>'stock_filter' );
		if ( in_array( $p->get_id(), $cfg['exclude_ids'], true ) || in_array( $base->get_id(), $cfg['exclude_ids'], true ) ) return array( 'skip'=>'product_filter' );
		if($cfg['sale_only']&&!$p->is_on_sale())return array('skip'=>'sale_filter');
		if($cfg['exclude_virtual']&&$p->is_virtual())return array('skip'=>'virtual_filter');
		$display=(float)wc_get_price_to_display($p);
		if((''!==$cfg['min_price']&&$display<(float)$cfg['min_price'])||(''!==$cfg['max_price']&&$display>(float)$cfg['max_price']))return array('skip'=>'price_filter');
		$cats = $base->get_category_ids();
		if($cfg['include_children'])foreach($cats as $cat)$cats=array_merge($cats,get_ancestors($cat,'product_cat','taxonomy'));
		if ( $cfg['include_categories'] && ! array_intersect( $cats, $cfg['include_categories'] ) ) return array( 'skip'=>'category_filter' );
		if ( $cfg['exclude_categories'] && array_intersect( $cats, $cfg['exclude_categories'] ) ) return array( 'skip'=>'category_filter' );
		if ( 'yes' === self::meta( $p, $parent, array( '_dxps_feed_exclude','wpfoof-exclude-product' ) ) ) return array( 'skip'=>'product_opt_out' );

		$row = array(
			'id' => DXPS_Helpers::pid( $p ),
			'title' => self::text( $p->get_name(), 150 ),
			'description' => self::text( $p->get_description() ?: $base->get_description() ?: $base->get_short_description() ),
			'link' => esc_url_raw( $p->get_permalink() ),
			'image_link' => wp_get_attachment_url( $p->get_image_id() ?: $base->get_image_id() ) ?: '',
			'availability' => $p->is_on_backorder( 1 ) ? 'backorder' : ( $p->is_in_stock() ? 'in_stock' : 'out_of_stock' ),
			'condition' => self::meta( $p, $parent, array( '_dxps_condition','wpfoof-condition' ) ) ?: $cfg['condition'],
			'brand' => self::meta( $p, $parent, array( '_dxps_brand','_wc_gla_brand','wpfoof-brand' ) ) ?: $cfg['brand'],
			'gtin' => self::meta( $p, $parent, array( '_global_unique_id','_dxps_gtin','_wc_gla_gtin','_gtin','wpfoof-gtin' ), false ),
			'mpn' => self::meta( $p, $parent, array( '_dxps_mpn','_wc_gla_mpn','wpfoof-mpn' ), false ),
			'google_product_category' => self::meta( $p, $parent, array( '_dxps_google_category','_wc_gla_google_category','feed_google_category','wpfoof-google_product_category' ) ) ?: $cfg['google_category'],
			'identifier_exists' => self::meta( $p, $parent, array( '_dxps_identifier_exists','wpfoof-identifier_exists' ) ),
			'availability_date' => self::meta( $p, $parent, array( '_dxps_availability_date' ) ),
		);
		if ( 'output' === $row['identifier_exists'] ) $row['identifier_exists'] = 'no';
		if ( ! $row['brand'] && taxonomy_exists( 'product_brand' ) ) $row['brand'] = self::mapped( 'taxonomy:product_brand', $p, $parent );
		if ( $parent ) $row['item_group_id'] = DXPS_Helpers::pid( $parent );
		$row['product_type'] = self::category_path( $base->get_category_ids() );
		$images = array();
		// A variation shown with its own photo keeps the parent's main photo as an extra view.
		$gallery = $base->get_gallery_image_ids();
		if ( $parent && $p->get_image_id() && $base->get_image_id() && (int) $p->get_image_id() !== (int) $base->get_image_id() ) array_unshift( $gallery, $base->get_image_id() );
		foreach ( array_slice( $gallery, 0, 10 ) as $image ) { $url = wp_get_attachment_url( $image ); if ( $url && $url !== $row['image_link'] && self::valid_url( $url ) ) $images[] = $url; }
		$row['additional_image_link'] = array_values( array_unique( $images ) );
		foreach ( array( 'color','size','gender','age_group','material','pattern','size_type','size_system' ) as $field ) {
			$row[$field] = self::meta( $p, $parent, array( '_dxps_' . $field, 'wpfoof-' . $field ) );
			if ( ! $row[$field] ) $row[$field] = self::attribute( $p, $field );
		}
		foreach ( array( 'shipping_label','tax_category','adult','multipack','is_bundle','custom_label_0','custom_label_1','custom_label_2','custom_label_3','custom_label_4' ) as $field ) $row[$field] = self::meta( $p, $parent, array( '_dxps_' . $field, 'wpfoof-' . $field ) );
		if ( '' !== $p->get_weight() ) $row['shipping_weight'] = wc_get_weight( (float) $p->get_weight(), 'kg' ) . ' kg';
		// Meta stops delivering ads for an item once this reaches zero - no spend on products that just sold out.
		if ( $p->managing_stock() && null !== $p->get_stock_quantity() ) $row['quantity_to_sell_on_facebook'] = (string) max( 0, (int) $p->get_stock_quantity() );

		$current = $p->get_price(); $regular = $p->get_regular_price();
		if ( '' === $current || ! is_numeric( $current ) || (float) $current <= 0 ) $errors[] = 'missing_or_nonpositive_price';
		$money = function( $price ) use ( $p, $cfg ) {
			$args = array( 'price'=>(float) $price, 'qty'=>1 );
			$value = 'including' === $cfg['tax_mode'] ? wc_get_price_including_tax( $p, $args ) : ( 'excluding' === $cfg['tax_mode'] ? wc_get_price_excluding_tax( $p, $args ) : wc_get_price_to_display( $p, $args ) );
			return number_format( (float) $value, 2, '.', '' ) . ' ' . get_woocommerce_currency();
		};
		$row['price'] = $money( $current );
		if ( $p->is_on_sale() && is_numeric( $regular ) && (float) $regular > (float) $current ) {
			$row['price'] = $money( $regular ); $row['sale_price'] = $money( $current );
			$from = $p->get_date_on_sale_from(); $to = $p->get_date_on_sale_to();
			if ( $from && $to ) $row['sale_price_effective_date'] = $from->date( DATE_ATOM ) . '/' . $to->date( DATE_ATOM );
		}
		if ( ! empty( $cfg['auto_labels'] ) ) foreach ( self::auto_labels( $p, $base, $cfg, (float) wc_get_price_to_display( $p ) ) as $field=>$label ) if ( '' === (string) ( $row[$field] ?? '' ) ) $row[$field] = $label;
		$row=DXPS_Feed_Options::enrich($row,$p,$base,$cfg);
		foreach ( $cfg['mapping'] as $field=>$source ) if ( in_array( $field, self::MAPPABLE, true ) ) { $value = self::mapped( $source, $p, $parent ); if ( '' !== $value ) $row[$field] = $value; }
		// A product without any description was dropped from the catalogue before 2.2.0. The title is a truthful
		// minimum; the report still asks for a real description.
		if ( '' === self::text( $row['description'] ?? '' ) ) {
			$row['description'] = self::text( $p->get_short_description() ?: $base->get_short_description() ) ?: $row['title'];
			$warnings[] = 'description_missing_fallback_used';
		}
		// Backorders without a date: follow the feed's backorder policy instead of always excluding the offer.
		if ( 'backorder' === $row['availability'] ) {
			$date = (string) ( $row['availability_date'] ?? '' );
			$dated = preg_match( '/^\d{4}-\d\d-\d\dT/', $date ) && strtotime( $date ) > time();
			if ( ! $dated && 'estimate' === $cfg['backorder_mode'] ) $row['availability_date'] = gmdate( 'Y-m-d\TH:i\Z', time() + max( 1, (int) $cfg['backorder_days'] ) * DAY_IN_SECONDS );
			elseif ( ! $dated && in_array( $cfg['backorder_mode'], array( 'in_stock', 'out_of_stock' ), true ) ) { $row['availability'] = $cfg['backorder_mode']; unset( $row['availability_date'] ); }
		}
		if ( $cfg['link_params'] ) {
			parse_str( $cfg['link_params'], $extra );
			foreach ( $extra as $k=>$v ) $extra[$k] = str_replace( '{id}', $row['id'], (string) $v );
			$row['link'] = esc_url_raw( add_query_arg( array_map( 'rawurlencode', $extra ), $row['link'] ) );
		}
		if('none'===$cfg['identifier_mode']&&$cfg['identifier_confirmed']&&empty($row['gtin'])&&empty($row['mpn'])&&empty($row['identifier_exists']))$row['identifier_exists']='no';
		foreach ( $row as $k=>$v ) if ( ! is_array( $v ) ) $row[$k] = self::text( $v, 'description' === $k ? 5000 : ( 'title' === $k ? 150 : 2000 ) );
		$row = apply_filters( 'dxps_feed_product_row', $row, $p, $cfg );
		$row = array_intersect_key( (array) $row, array_flip( self::FIELDS ) );
		foreach ( self::validate( $row, $cfg ) as $severity=>$issues ) { if ( 'errors' === $severity ) $errors = array_merge( $errors, $issues ); else $warnings = array_merge( $warnings, $issues ); }
		if ( 'sku' === DXPS_Helpers::opt( 'product_id_format', 'id' ) && $p->get_sku() && (int) wc_get_product_id_by_sku( $p->get_sku() ) !== (int) $p->get_id() ) $errors[] = 'duplicate_sku';
		return array( 'row'=>$row, 'errors'=>array_unique( $errors ), 'warnings'=>array_unique( $warnings ) );
	}

	public static function validate( $row, $cfg ) {
		$errors = array(); $warnings = array();
		foreach ( array( 'id','title','description','link','image_link','availability','price','condition' ) as $key ) if ( ! isset( $row[$key] ) || ! is_string( $row[$key] ) || '' === trim( $row[$key] ) ) $errors[] = 'missing_' . $key;
		if ( strlen( $row['id'] ?? '' ) > 50 ) $errors[] = 'id_over_50_bytes';
		foreach ( array( 'link','image_link' ) as $key ) if ( ! self::valid_url( $row[$key] ?? '' ) ) $errors[] = 'invalid_' . $key;
		if ( ! preg_match( '/^\d+\.\d{2} [A-Z]{3}$/', $row['price'] ?? '' ) || (float) ( $row['price'] ?? 0 ) <= 0 ) $errors[] = 'invalid_price';
		if ( ! in_array( $row['availability'] ?? '', array( 'in_stock','out_of_stock','preorder','backorder' ), true ) ) $errors[] = 'invalid_availability';
		if ( ! in_array( $row['condition'] ?? '', array( 'new','used','refurbished' ), true ) ) $errors[] = 'invalid_condition';
		if ( ! empty( $row['gtin'] ) && ! self::valid_gtin( $row['gtin'] ) ) $errors[] = 'invalid_gtin_checksum';
		$no_identifiers = in_array( strtolower( $row['identifier_exists'] ?? '' ), array( 'no','false','0' ), true );
		if ( $no_identifiers && ( ! empty( $row['gtin'] ) || ! empty( $row['mpn'] ) ) ) $errors[] = 'contradictory_identifier_exists';
		if ( ! empty( $row['identifier_exists'] ) && ! in_array( strtolower( $row['identifier_exists'] ), array( 'yes','no','true','false','0','1' ), true ) ) $errors[] = 'invalid_identifier_exists';
		if ( ! $no_identifiers && empty( $row['gtin'] ) && ( empty( $row['brand'] ) || empty( $row['mpn'] ) ) ) $warnings[] = 'identifiers_missing_use_real_manufacturer_data';
		if(('required'===($cfg['identifier_mode']??''))&&empty($row['gtin'])&&(empty($row['brand'])||empty($row['mpn'])))$errors[]='identifiers_required';
		if ( empty( $row['brand'] ) ) $warnings[] = 'brand_missing';
		if ( empty( $row['google_product_category'] ) ) $warnings[] = 'google_category_missing';
		// Meta publishes these as "preorder" / "available for order" and does not ask for a date.
		if ( 'meta' !== $cfg['platform'] && in_array( $row['availability'] ?? '', array( 'preorder','backorder' ), true ) ) {
			$date = $row['availability_date'] ?? '';
			if ( ! preg_match( '/^\d{4}-\d\d-\d\dT\d\d:\d\d(?::\d\d)?(?:Z|[+-]\d\d:\d\d)$/', $date ) || strtotime( $date ) <= time() ) $errors[] = 'future_availability_date_required';
		}
		if ( 'pinterest' === $cfg['platform'] && 'backorder' === ( $row['availability'] ?? '' ) ) $errors[] = 'pinterest_backorder_not_supported';
		$enums = array( 'gender'=>array( 'male','female','unisex' ), 'age_group'=>array( 'newborn','infant','toddler','kids','adult' ), 'size_type'=>array( 'regular','petite','plus','tall','big','maternity' ), 'adult'=>array( 'yes','no','true','false' ), 'is_bundle'=>array( 'yes','no','true','false' ) );
		foreach ( $enums as $key=>$allowed ) if ( ! empty( $row[$key] ) && ! in_array( $row[$key], $allowed, true ) ) $errors[] = 'invalid_' . $key;
		if ( ! empty( $cfg['apparel'] ) ) foreach ( array( 'gender','age_group','color','size' ) as $key ) if ( empty( $row[$key] ) ) $errors[] = 'apparel_requires_' . $key;
		if ( ! empty( $row['item_group_id'] ) && empty( $row['color'] ) && empty( $row['size'] ) && empty( $row['material'] ) && empty( $row['pattern'] ) ) $warnings[] = 'variant_attributes_missing';
		if ( isset( $row['sale_price'] ) && ( ! preg_match( '/^\d+\.\d{2} [A-Z]{3}$/', $row['sale_price'] ) || (float) $row['sale_price'] <= 0 || (float) $row['sale_price'] >= (float) $row['price'] || substr( $row['sale_price'], -3 ) !== substr( $row['price'], -3 ) ) ) $errors[] = 'invalid_sale_price';
		return array( 'errors'=>$errors, 'warnings'=>$warnings );
	}
}
