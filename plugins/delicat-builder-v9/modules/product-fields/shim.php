<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Currency adapter for Product Fields/Calculator. No legacy DMC globals. */
if ( ! class_exists( 'Delicat_Builder_V9_DMC_Currencies_Shim', false ) ) {
	final class Delicat_Builder_V9_DMC_Currencies_Shim {
		public function current() { return function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'HTG'; }
		public function get( $code ) { $code = strtoupper( sanitize_key( (string) $code ) ); return array( 'code'=>$code, 'symbol'=>function_exists('get_woocommerce_currency_symbol')?get_woocommerce_currency_symbol($code):$code, 'decimals'=>function_exists('wc_get_price_decimals')?wc_get_price_decimals():2, 'position'=>get_option('woocommerce_currency_pos','left') ); }
		public function rate( $code ) { return 1.0; }
	}
}
if ( ! class_exists( 'Delicat_Builder_V9_DMC_Price_Shim', false ) ) {
	final class Delicat_Builder_V9_DMC_Price_Shim { public function no_convert( $product ) { return $product instanceof WC_Product && 'yes' === (string) $product->get_meta( '_dmc_no_convert', true ); } }
}
if ( ! class_exists( 'Delicat_Builder_V9_DMC_Runtime_Shim', false ) ) {
	final class Delicat_Builder_V9_DMC_Runtime_Shim { public $currencies; public $price; public function __construct(){ $this->currencies=new Delicat_Builder_V9_DMC_Currencies_Shim(); $this->price=new Delicat_Builder_V9_DMC_Price_Shim(); } }
}
if ( ! function_exists( 'delicat_builder_v9_currency_runtime' ) ) {
	function delicat_builder_v9_currency_runtime() {
		if ( class_exists( 'Delicat_Builder_V9_Multi_Currency', false ) ) { return Delicat_Builder_V9_Multi_Currency::instance(); }
		static $shim = null; if ( null === $shim ) { $shim = new Delicat_Builder_V9_DMC_Runtime_Shim(); } return $shim;
	}
}
