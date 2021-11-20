<?php
/** Auto Order Creation on Auction End
 * When an auction ends programmatically create a WooCommerce order from
 * the auction item. This skips the checkout process completely .
 * Works for auction sites that want to accept payments offline / in person.
 * @author Alex Stillwagon
 * @url https://alexstillwagon.com
 * @package Auctions for WooCommerce\ASD\Includes
 * @version 1.1
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

	//Change Order Status (see Notes above)
	$order->update_status( 'processing', "Auto Order on Auction End - ", true );
	// Save Orders
	$order->save();
	//endregion Save Order to Database
}
