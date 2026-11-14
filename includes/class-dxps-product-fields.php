<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Actual manufacturer identifiers; never synthesize a GTIN or substitute SKU for MPN. */
class DXPS_Product_Fields {
	const FIELDS = array( 'brand'=>'Brand', 'gtin'=>'GTIN (exact product/variant)', 'mpn'=>'Manufacturer part number', 'google_category'=>'Google category ID or full path', 'color'=>'Color', 'size'=>'Size', 'gender'=>'Gender: male, female or unisex', 'age_group'=>'Age group: newborn, infant, toddler, kids or adult', 'availability_date'=>'Available on (ISO 8601 with timezone)', 'custom_label_0'=>'Custom label 0' );
	public static function init() {
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'tab' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'panel' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save' ) );
		add_action( 'woocommerce_product_after_variable_attributes', array( __CLASS__, 'variation' ), 10, 3 );
		add_action( 'woocommerce_admin_process_variation_object', array( __CLASS__, 'save_variation' ), 10, 2 );
	}
	public static function tab( $tabs ) { $tabs['dxps_catalog']=array( 'label'=>'DX Catalogue','target'=>'dxps_catalog','priority'=>80 ); return $tabs; }
	public static function panel() {
		echo '<div id="dxps_catalog" class="panel woocommerce_options_panel hidden"><div class="options_group">';
		wp_nonce_field( 'dxps_product_fields', 'dxps_product_nonce' );
		foreach ( self::FIELDS as $key=>$label ) woocommerce_wp_text_input( array( 'id'=>'_dxps_' . $key,'label'=>$label ) );
		woocommerce_wp_select( array( 'id'=>'_dxps_identifier_exists','label'=>'Manufacturer identifiers','options'=>array( ''=>'Default / unknown','yes'=>'Identifiers exist','no'=>'Manufacturer has assigned no identifiers' ) ) );
		woocommerce_wp_checkbox( array( 'id'=>'_dxps_feed_exclude','label'=>'Exclude from every DX feed' ) );
		echo '<p class="form-field">Variation identifiers must be supplied separately. Built-in WooCommerce Global Unique ID is also supported.</p></div></div>';
	}
	private static function authorized( $id ) { return current_user_can( 'edit_post', $id ) && isset( $_POST['dxps_product_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dxps_product_nonce'] ) ), 'dxps_product_fields' ); }
	public static function save( $p ) {
		if ( ! self::authorized( $p->get_id() ) ) return;
		foreach ( self::FIELDS + array( 'identifier_exists'=>'' ) as $key=>$label ) { $v = $_POST['_dxps_' . $key] ?? ''; $p->update_meta_data( '_dxps_' . $key, DXPS_Feed_Product::text( wp_unslash( $v ), 500 ) ); }
		$p->update_meta_data( '_dxps_feed_exclude', isset( $_POST['_dxps_feed_exclude'] ) ? 'yes' : 'no' );
	}
	public static function variation( $loop, $data, $variation ) {
		wp_nonce_field( 'dxps_product_fields', 'dxps_product_nonce' );
		$p = wc_get_product( $variation->ID );
		foreach ( array( 'gtin','mpn','color','size' ) as $key ) woocommerce_wp_text_input( array( 'id'=>'dxps_var_' . $key . '_' . $loop, 'name'=>'dxps_variation[' . $loop . '][' . $key . ']', 'label'=>'DX ' . self::FIELDS[$key], 'value'=>$p ? $p->get_meta( '_dxps_' . $key ) : '', 'wrapper_class'=>'form-row form-row-full' ) );
	}
	public static function save_variation( $p, $i ) {
		if ( ! self::authorized( $p->get_parent_id() ) || ! isset( $_POST['dxps_variation'][$i] ) ) return;
		foreach ( array( 'gtin','mpn','color','size' ) as $key ) if ( isset( $_POST['dxps_variation'][$i][$key] ) ) $p->update_meta_data( '_dxps_' . $key, DXPS_Feed_Product::text( wp_unslash( $_POST['dxps_variation'][$i][$key] ), 150 ) );
	}
}
