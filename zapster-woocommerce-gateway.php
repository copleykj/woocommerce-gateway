<?php
/*
Plugin Name: 		Zapster XRP Payment Gateway
Plugin URI: 		https://zapster.io
Description: 		Zapster XRP Payment Gateway is a FREE and convenient way to accept XRP payments from your website directly to your Ripple Wallet. We provide Instant Payment Notification services for XRP Payments with no fees or transaction costs.
Version: 			1.0.0
Author: 			Evolve Software Ltd
Author URI: 		http://evsoftware.co.uk
License: 			GPLv2 or later
*/

/*
This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.

Copyright 2005-2015 Automattic, Inc.
*/

if (!defined( 'ABSPATH' )) exit; // Exit if accessed directly  

add_action( 'plugins_loaded', 'woocommerce_zapster_init', 0 );
function woocommerce_zapster_init() {

	if ( ! class_exists( 'WC_Payment_Gateway' ) ) return;
	include_once( 'zapster-woocommerce.php' );

	add_filter( 'woocommerce_payment_gateways', 'woocommerce_add_zapster_gateway' );
	function woocommerce_add_zapster_gateway( $methods ) {
		$methods[] = 'WC_Zapster';
		return $methods;
	}

	add_filter( 'woocommerce_currencies', 'add_my_currency' );
	function add_my_currency( $currencies ) {
		$currencies['XRP'] = __( 'XRP', 'woocommerce' );
		return $currencies;
	}

	add_filter('woocommerce_currency_symbol', 'add_my_currency_symbol', 10, 2);
	function add_my_currency_symbol( $currency_symbol, $currency ) {
		switch( $currency ) {
			case 'XRP': $currency_symbol = '$XRP'; break;
		}
		return $currency_symbol;
	}
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'woocommerce_zapster_action_links' );
function woocommerce_zapster_action_links( $links ) {
	$plugin_links = array(
		'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout' ) . '">' . __( 'Settings', 'zapster' ) . '</a>',
	);
	return array_merge( $plugin_links, $links );
} 