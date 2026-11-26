<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 2.4.0: recognise returning buyers from their order emails.
 *
 * Links to the shop in WooCommerce customer emails about an order (processing, completed, on hold, invoice, customer
 * note ...) get a signed token, dxr=<order>.<expiry>.<signature>. It carries no personal data. When the customer
 * opens such a link and has given marketing consent on the site, the browser hands the token to /dxps/v1/id, which
 * stores the order's hashed matching fields in the visitor's own _dxps_ud cookie (DXPS_Server::identify()).
 * Only orders whose buyer allowed marketing at checkout get tokens.
 */
class DXPS_Email_Identity {

	/** Order of the customer email currently being built. */
	private static $order = null;

	public static function init() {
		if ( ! DXPS_Helpers::on( 'email_identity' ) ) return;
		add_action( 'woocommerce_email_header', array( __CLASS__, 'remember' ), 1, 2 );
		add_filter( 'woocommerce_mail_content', array( __CLASS__, 'rewrite' ), 20 );
	}

	/** woocommerce_email_header: note the order, only for emails sent to the customer about an order. */
	public static function remember( $heading = '', $email = null ) {
		self::$order = null;
		if ( ! is_object( $email ) || ! method_exists( $email, 'is_customer_email' ) || ! $email->is_customer_email() ) return;
		$order = isset( $email->object ) ? $email->object : null;
		if ( $order instanceof WC_Order && '1' === (string) $order->get_meta( '_dxps_marketing_allowed' ) ) self::$order = $order;
	}

	/** woocommerce_mail_content: add the token to every link that points to this site. */
	public static function rewrite( $content ) {
		$order = self::$order;
		self::$order = null;
		if ( ! $order || ! is_string( $content ) || '' === $content ) return $content;
		return self::add_token( $content, DXPS_Helpers::identity_token( $order->get_id() ) );
	}

	/** Adds dxr=<token> to the href of each link to this site (www or bare), leaving other links untouched. */
	public static function add_token( $html, $token ) {
		return preg_replace_callback( '/(<a\s[^>]*?href=)(["\'])([^"\']+)\2/i', function ( $m ) use ( $token ) {
			$url = html_entity_decode( $m[3], ENT_QUOTES );
			if ( ! DXPS_Helpers::same_site_origin( $url ) || false !== strpos( $url, 'dxr=' ) ) return $m[0];
			$parts = explode( '#', $url, 2 );
			$url   = $parts[0] . ( false === strpos( $parts[0], '?' ) ? '?' : '&' ) . 'dxr=' . rawurlencode( $token ) . ( isset( $parts[1] ) ? '#' . $parts[1] : '' );
			return $m[1] . $m[2] . esc_attr( $url ) . $m[2];
		}, $html );
	}
}
