<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly 
// giveaway add to cart validation
add_filter('woocommerce_add_to_cart_validation', 'wxgiveaway_giveaway_add_to_cart_validation', 10, 4);
function wxgiveaway_giveaway_add_to_cart_validation($passed, $product_id, $quantity,$variation_id=0) {
    if(wxgiveaway_is_giveaway($product_id)){
        if(!wxgiveaway_is_active($product_id)){
            $passed = false;
    
            $c_date = wxgiveaway_close_date_time($product_id);
            $s_date = wxgiveaway_start_date_time($product_id);

            if(strtotime(current_time('Y-m-d H:i:s'))<strtotime($s_date)){
                // translators: %s is the title of the giveaway producthe giveaway "%s".', 'giveaway-lottery'));

                wc_add_notice(sprintf(apply_filters('wxgiveaway_ticket_selling_not_started_msg',__('Ticket selling is not started yet for the giveaway "%s".', 'giveaway-lottery')), get_the_title($product_id)), 'error');
            }

            if(strtotime(current_time('Y-m-d H:i:s'))>strtotime($c_date)){
                // translators: %s is the title of the giveaway product
                wc_add_notice(sprintf(apply_filters('wxgiveaway_ticket_selling_closed_msg',__('Ticket selling is closed for the giveaway "%s".', 'giveaway-lottery')), get_the_title($product_id)), 'error');
            }

        }
    
        $ticket_range = get_post_meta($product_id, '_ticket_range', true);
        $allocated_entries = wxgiveaway_get_total_number_of_tickets($product_id);
        
        // phpcs:ignore
        global $wpdb;

        // Fetch the count of sold tickets
        // phpcs:ignore
        $row_counts = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(id) FROM {$wpdb->prefix}wxgiveaway WHERE giveaways_id = %d",
            $product_id
        ));

    
        // Calculate left tickets
        $post_id = (($variation_id>0)?$variation_id:$product_id);

        $left_tickets = ($allocated_entries - $row_counts);// prevent negative value

        $_no_of_tickets = (int)get_post_meta($post_id, '_no_of_tickets', true);

        $_bonus_tickets = 0;
        if (class_exists('Wx_Giveway_Lottry_for_Woocommerce_Pro')) {
            $_bonus_tickets = (int)get_post_meta($post_id, '_bonus_tickets', true);
        }

        $tickets = ($_no_of_tickets + $_bonus_tickets)*$quantity;

        if ($left_tickets < $tickets  ){
            $passed = false;
            // translators: %1$d is the number of tickets left, %2$s is the product title.
            wc_add_notice(sprintf(apply_filters('wxgiveaway_ticket_not_available_msg',__('Ticket is sold. Tickets left: %1$d for "%2$s".', 'giveaway-lottery')),$left_tickets, get_the_title($product_id)),'error');

        }
    }

    return $passed;
}

// validate cart at checkout process
add_action( 'woocommerce_after_checkout_validation', 'wxgiveaway_validate_cart_giveaway_items_at_checkout', 10, 2 );
function wxgiveaway_validate_cart_giveaway_items_at_checkout($fields, $errors){
    foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
        $product_id = (int) $cart_item['product_id'];
        $quantity = (int) $cart_item['quantity'];
        if(wxgiveaway_is_giveaway($product_id)){
            if(!wxgiveaway_is_active($product_id)){
                // translators: %s is the giveaway product title.
                $errors->add( 'validation', sprintf(apply_filters('wxgiveaway_ticket_not_active_msg',__('Giveaway "%s" is not active.','giveaway-lottery')), get_the_title($product_id)) );
            }else{

                // obtain ticket validation
                // Calculate left tickets
                $variation_id = 0;
                if ( isset( $cart_item['variation_id'] ) && $cart_item['variation_id'] ) {
                    $variation_id = $cart_item['variation_id'];
                }

                $post_id = (($variation_id>0)?$variation_id:$product_id);        
                // phpcs:ignore
                global $wpdb;

                // Fetch the count of sold tickets
                // phpcs:ignore
                $row_counts = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(id) FROM {$wpdb->prefix}wxgiveaway WHERE giveaways_id = %d",
                    $product_id
                ));

                $allocated_entries = wxgiveaway_get_total_number_of_tickets($product_id);
                $left_tickets = ($allocated_entries - $row_counts);// prevent negative value

                $_no_of_tickets = (int)get_post_meta($post_id, '_no_of_tickets', true);

                $_bonus_tickets = 0;
                if (class_exists('Wx_Giveway_Lottry_for_Woocommerce_Pro')) {
                    $_bonus_tickets = (int)get_post_meta($post_id, '_bonus_tickets', true);
                }

                $tickets = ($_no_of_tickets + $_bonus_tickets)*$quantity;

                if ($left_tickets < $tickets  ){
                    $passed = false;
                    // translators: %1$d is the number of tickets left, %2$s is the product title.
                    $errors->add( 'validation',sprintf(apply_filters('wxgiveaway_ticket_not_available_msg',__('Ticket is sold. Tickets left: %1$d for "%1$s".', 'giveaway-lottery')),$left_tickets, get_the_title($product_id)),'error');

                }
            }
        }
        
    }
}

add_action('woocommerce_store_api_cart_errors','wxgiveaway_validate_cart_giveaway_items_at_checkout_store_api', 10, 2);
function wxgiveaway_validate_cart_giveaway_items_at_checkout_store_api($errors, $cart){
    foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
        $product_id = (int) $cart_item['product_id'];
        $quantity = (int) $cart_item['quantity'];
        if(wxgiveaway_is_giveaway($product_id)){
            if(!wxgiveaway_is_active($product_id)){
                // translators: %s is the giveaway product title.
                $errors->add( 'validation', sprintf(apply_filters('wxgiveaway_ticket_not_active_msg',__('Giveaway "%s" is not active.','giveaway-lottery')), get_the_title($product_id)) );
            }else{

                // obtain ticket validation
                // Calculate left tickets
                $variation_id = 0;
                if ( isset( $cart_item['variation_id'] ) && $cart_item['variation_id'] ) {
                    $variation_id = $cart_item['variation_id'];
                }

                $post_id = (($variation_id>0)?$variation_id:$product_id);        
                // phpcs:ignore
                global $wpdb;

                // Fetch the count of sold tickets
                // phpcs:ignore
                $row_counts = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(id) FROM {$wpdb->prefix}wxgiveaway WHERE giveaways_id = %d",
                    $product_id
                ));

                $allocated_entries = wxgiveaway_get_total_number_of_tickets($product_id);
                $left_tickets = ($allocated_entries - $row_counts);// prevent negative value

                $_no_of_tickets = (int)get_post_meta($post_id, '_no_of_tickets', true);

                $_bonus_tickets = 0;
                if (class_exists('Wx_Giveway_Lottry_for_Woocommerce_Pro')) {
                    $_bonus_tickets = (int)get_post_meta($post_id, '_bonus_tickets', true);
                }

                $tickets = ($_no_of_tickets + $_bonus_tickets)*(int) $cart_item['quantity'];

                if ($left_tickets < $tickets  ){
                    $passed = false;
                    // translators: %1$d is the number of tickets left, %2$s is the product title.
                    $errors->add( 'validation',sprintf(apply_filters('wxgiveaway_ticket_not_available_msg',__('Ticket is sold. Tickets left: %1$d for "%1$s".', 'giveaway-lottery')),$left_tickets, get_the_title($product_id)),'error');

                }
            }
        }
        
    }
    return $errors;
}