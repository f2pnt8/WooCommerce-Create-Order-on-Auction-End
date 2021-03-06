<?php
/** Auto Order Creation on Auction End
 * When an auction ends programmatically create a WooCommerce order from
 * the auction item. This skips the checkout process completely .
 * Works for auction sites that want to accept payments offline / in person.
 * @author Alex Stillwagon
 * @url https://alexstillwagon.com
 * @package Auctions for WooCommerce\ASD\Includes
 * @version 1.2
 * @updated Nov 2021
 * Note: The incorrect spelling of 'bider' is the "correct" metadata key
 * from the auction plugin.
 */

//region Security =============================

// Exit if file is accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit();
}
//endregion Security

/**
 * Trigger code execution on auction completion
 * Only triggers if an auction has a bid
 */
add_action( 'auctions_for_woocommerce_won', 'asd_create_order', 10, 1 );

/**
 * Create the order
 *
 * @param int $id // Product ID of the auction item sent from WooCommerce
 *
 * @return void
 * @throws WC_Data_Exception
 * @throws Exception
 */
function asd_create_order( int $id ): void {

	//region Bidder Info ---------------------------------------------

	//Get the ID of the User that won the auction
	$high_bidder = (int) get_post_meta( $id, '_auction_current_bider', true );

	// Get the User's Data
	$high_bidder_data = get_userdata( $high_bidder );

	//endregion Bidder Info

	//region Order Data ---------------------------------------------

	// Create the order
	$order = wc_create_order(
		[
			// Set Order Buyer
			'customer_id' => $high_bidder,

			/**
			 * Set Order Status
			 * Warning: If you set the status and then update it later (see Save Order to Database region below)
			 * do not update the status to a previous step in the order process
			 * or duplicate orders will be created.
			 * (i.e. do not set "processing" then update it to "pending-payment" )
			 * Possible WC Order Statuses:
			 * ( in order of process steps)
			 * 1. pending-payment
			 * 2. processing
			 * 3. on-hold
			 * 4. completed
			 * 5. cancelled
			 * 6. refunded
			 * 7. failed
			 */
			'status'      => 'pending-payment',
		]
	);

	//Add the item to the order
	$order->add_product( wc_get_product( $id ) );

	//endregion Order Data

	//region Customer Info ---------------------------------------------
	$fname     = get_user_meta( $high_bidder, 'first_name', true );
	$lname     = get_user_meta( $high_bidder, 'last_name', true );
	$email     = $high_bidder_data->user_email;
	$address_1 = get_user_meta( $high_bidder, 'billing_address_1', true );
	$address_2 = get_user_meta( $high_bidder, 'billing_address_2', true );
	$city      = get_user_meta( $high_bidder, 'billing_city', true );
	$postcode  = get_user_meta( $high_bidder, 'billing_postcode', true );
	$country   = get_user_meta( $high_bidder, 'billing_country', true );
	$state     = get_user_meta( $high_bidder, 'billing_state', true );
	//endregion Customer Info

	//region Addresses ---------------------------------------------

	// Billing Address
	$billing_address = [
		'first_name' => $fname,
		'last_name'  => $lname,
		'email'      => $email,
		'address_1'  => $address_1,
		'address_2'  => $address_2,
		'city'       => $city,
		'state'      => $state,
		'postcode'   => $postcode,
		'country'    => $country,
	];

	// Shipping Address
	$address = [
		'first_name' => $fname,
		'last_name'  => $lname,
		'email'      => $email,
		'address_1'  => $address_1,
		'address_2'  => $address_2,
		'city'       => $city,
		'state'      => $state,
		'postcode'   => $postcode,
		'country'    => $country,
	];

	$order->set_address( $billing_address ); // 'billing' is the default arg

	$order->set_address( $address, 'shipping' );

	//endregion Addresses

	//region Shipping Info ---------------------------------------------
	// ( Uncomment if needed )

	//$shipping_cost   = 5;
	//$shipping_method = 'Fedex';

	//$order->add_shipping( $shipping_cost );

	//$order->shipping_method_title = $shipping_method;

	//endregion Shipping Info

	//region Payment ---------------------------------------------
	// Get payment gateways from WooCommerce settings
	$payment_gateways = WC()->payment_gateways->payment_gateways();

	// Set Payment as Cash on Delivery
	$order->set_payment_method( $payment_gateways[ 'cod' ] );

	$order->calculate_totals();

	//endregion Payment

	//region Save Order to Database ---------------------------------------------

	$order->set_customer_ip_address( WC_Geolocation::get_ip_address() );
	$order->set_customer_user_agent( wc_get_user_agent() );
	$order->set_currency( get_woocommerce_currency() );
	$order->set_customer_id( $high_bidder );
	$order->set_created_via( 'automatic' );
	//Change Order Status (see Notes above)
	$order->update_status( 'processing', "Auto Order on Auction End - ", true );

	// Save the Order
	$order_id = $order->save();
	//endregion Save Order to Database

	asd_save_order_meta( $order_id );
}

/**
 * Save the Order Metadata used by the Auction Plugin
 * Code copied from Auction for WooCommerce plugin
 * wp-content/plugins/auctions-for-woocommerce/admin/class-auctions-for-woocommerce-admin.php
 * @return void
 * @throws Exception
 */
function asd_save_order_meta( $order_id ) {

	$order = wc_get_order( $order_id );

	if ( $order ) {

		$order_items = $order->get_items();

		if ( $order_items ) {
			foreach ( $order_items as $item_id => $item ) {
				if ( function_exists( 'wc_get_order_item_meta' ) ) {
					$item_meta = wc_get_order_item_meta( $item_id, '' );
				} else {
					$item_meta = method_exists( $order, 'wc_get_order_item_meta' ) ? $order->wc_get_order_item_meta( $item_id ) : $order->get_item_meta( $item_id );
				}
				$product_id   = $item_meta[ '_product_id' ][ 0 ];
				$product_data = wc_get_product( $product_id );
				if ( $product_data && $product_data->is_type( 'auction' ) ) {
					update_post_meta( $order_id, '_auction', '1' );
					update_post_meta( $product_id, '_order_id', $order_id, true );
					update_post_meta( $product_id, '_stop_mails', '1' );
					if ( ! $product_data->is_finished() ) {
						wp_set_post_terms( $product_id, [ 'buy-now', 'finished' ], 'auction_visibility', true );
						update_post_meta( $product_id, '_buy_now', '1' );
						update_post_meta( $product_id, '_auction_dates_to', gmdate( 'Y-m-h h:s' ) );
						do_action( 'auctions_for_woocommerce_close_buynow', $product_id );
					}
				}
			}
		}
	}
}
