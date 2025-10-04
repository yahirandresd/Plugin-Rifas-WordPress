<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly 
/**
 * Utility method to create ticket number for each line item from order 
 */
function wxgiveaway_generate_giveaway_tickets($giveaway_id, $order_id, $tickets, $order_item_id, $min, $max, $variation_id = 0) {
    global $wpdb;

    $table_name       = $wpdb->prefix . 'wxgiveaway';

    $generatedTickets = [];
    $selected_tickets_raw = wc_get_order_item_meta($order_item_id, '_wxgiveaway_selected_tickets', true);

    if (!empty($selected_tickets_raw)){
        do_action('selected_ticket_ticket_picker_update_wxgiveaway', $selected_tickets_raw, $giveaway_id, $order_id, $order_item_id, $variation_id, $table_name);
    }else{
        // phpcs:ignore
        $wpdb->query("CALL GenerateWXGiveawayTickets($giveaway_id,$order_id,$tickets,$min, $max,$order_item_id,$variation_id)");
    }

    do_action('wxgiveaway_after_generate_tickets', $giveaway_id, $order_id, $tickets, $order_item_id, $min, $max, $variation_id);

}

add_action( 'woocommerce_order_status_changed', 'wxgiveaway_giveaway_ticket_generate', 10, 3);
function wxgiveaway_giveaway_ticket_generate($order_id,$old_status, $new_status){

    $order                  = wc_get_order( $order_id );
    $settings               = get_option('wxgiveaway_settings');
    $ticket_generate_status = $settings['ticket_generate'];
    $ticket_delete_status = $settings['ticket_delete_at'];
    $isTicketGenerated = (int) $order->get_meta('wxgiveaway_ticket_generated');

    if(empty($ticket_generate_status)){
        $ticket_generate_status = ['processing','completed'];
    }

    if(empty($ticket_delete_status)){
        $ticket_delete_status = ['on-hold','cancelled','refuned','failed','checkout-draft'];
    }

    // ticket generate

    if(in_array($new_status,$ticket_generate_status)){
        
        
        if( $isTicketGenerated!=1){
    
            // Get and Loop Over Order Items
            $totalTickets=0;
            foreach ( $order->get_items() as $item_id => $item ) {
                $product_id = $item->get_product_id();
				if(wxgiveaway_is_giveaway($product_id) && wxgiveaway_is_active($product_id)){
					
                $isSingleGiveaway = wxgiveaway_is_giveaway($product_id);

                $variation_id = $item->get_variation_id();
                $giveaway_id = $product_id;

                $post_id = ($variation_id>0?$variation_id:$product_id);

                $_no_of_tickets = (int)get_post_meta($post_id, '_no_of_tickets', true);

                $_bonus_tickets = 0;
                if (class_exists('Wx_Giveway_Lottry_for_Woocommerce_Pro')) {
                    $_bonus_tickets = (int)get_post_meta($post_id, '_bonus_tickets', true);
                }

                $quantity = $item->get_quantity();
                
                $total_tickets = $tickets * $quantity;
                

                $tickets = apply_filters( 'obtain_tickets', $tickets, $giveaway_id, $post_id, $order_id, $item_id,$_no_of_tickets,$_bonus_tickets);
                // var_dump($post_id);
                if($tickets>0){
                    // Retrieve the ticket range from post meta
                    $ticket_range = get_post_meta($giveaway_id, '_ticket_range', true);
                    $range = explode('-', $ticket_range);

                    if (is_array($range)) {
                        $min = isset($range[0]) ? intval(trim($range[0])) : WXGIVEAWAY_TICKET_MIN_VALUE;
                        $max = isset($range[1]) ? intval(trim($range[1])) : WXGIVEAWAY_TICKET_MAX_VALUE;
                    } else {
                        $min = WXGIVEAWAY_TICKET_MIN_VALUE;
                        $max = WXGIVEAWAY_TICKET_MAX_VALUE;
                    }

                    wxgiveaway_generate_giveaway_tickets($giveaway_id, $order_id, $tickets, $item_id, $min,$max,$variation_id);

                    $totalTickets+=$tickets;
                }
			}
            } // end foreach item

            $order->update_meta_data('total_obtain_tickets',$totalTickets);

            if($totalTickets>0){
                $order->update_meta_data('wxgiveaway_ticket_generated',1);
            }

            $order->save();
			if($totalTickets>0){
            	do_action('wxgiveaway_after_ticket_generate', $order);
			}
        }
    }

    // ticket delete
    if(in_array($new_status,$ticket_delete_status) && $isTicketGenerated == 1){
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query($wpdb->prepare("delete from {$wpdb->prefix}wxgiveaway where order_id = %d",$order_id));
        $order->delete_meta_data('total_obtain_tickets');
        $delete_wxgiveaway_ticket_generated = true;
        foreach ( $order->get_items() as $item_id => $item ) {

            $selected_tickets_raw = wc_get_order_item_meta($item_id, '_wxgiveaway_selected_tickets', true);

            if (!empty($selected_tickets_raw)){
                $delete_wxgiveaway_ticket_generated = false;
            }
            $item->delete_meta_data( 'no_of_tickets' );
            $item->save();
        }

        if($delete_wxgiveaway_ticket_generated) {
            $order->delete_meta_data('wxgiveaway_ticket_generated');
        }

        $order->save();
    }
}

// send tickets through email
add_action('wxgiveaway_after_ticket_generate','wxgiveaway_giveaway_ticket_send',99,1);
function wxgiveaway_giveaway_ticket_send($order){
    $order_id = $order->get_id();
    $order = wc_get_order($order_id);
    $settings=get_option('wxgiveaway_settings');    
    $ticket_send=(isset($settings['ticket_send'])?$settings['ticket_send']:'');
    $ticket_style=$settings['ticket_style'];
    $logo=$settings['logo_url'];
    if(!$ticket_style){
        $ticket_style='style1';
    }

    
    $wxgiveaway_ticket_generated=(int)$order->get_meta('wxgiveaway_ticket_generated');
    $total_obtain_tickets=(int)$order->get_meta('total_obtain_tickets');

    if($ticket_send && $wxgiveaway_ticket_generated===1){
        $email_subject = isset($settings['email_subject']) ? $settings['email_subject'] : __('Your Giveaway Lottery Ticket', 'giveaway-lottery');
        $email_content = isset($settings['additional_content']) ? $settings['additional_content'] : __('Thank you for participating in our giveaway lottery! Here are your tickets:', 'giveaway-lottery');
        $email_to = $order->get_billing_email();
        $email_to = apply_filters('wxgiveaway_email_to', $email_to, $order);
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $results=$wpdb->get_results($wpdb->prepare("SELECT a.*, b.post_title, c.meta_value FROM {$wpdb->prefix}wxgiveaway a, {$wpdb->prefix}posts b, {$wpdb->prefix}postmeta c 
        where a.giveaways_id=b.ID and a.giveaways_id=c.post_id and c.meta_key='_draw_date' and a.order_id = %d and a.order_id",$order->get_id()));

        ob_start();

        wxgiveaway_ticket_email_header();
        // phpcs:ignore
        $email_content = apply_filters('wxgiveaway_ticket_email_content_additional', $email_content, $order, $results, $ticket_style, $logo);

        $ticketHtmlOutput = ''; // phpcs:ignore
        
        if($results){
            $ticketsHtml=array();
			
			
            foreach($results as $row){
                $ticketsHtml[]=wxgiveaway_get_ticket_for_selected_style($row,$ticket_style,$order,$logo);
            } // end foreach loop
            if(count($ticketsHtml)>0){
                if($ticket_style==='style1'){
                    $ticketHtmlOutput = wxgiveaway_print_ticket_style1($ticketsHtml);
                }
                if($ticket_style==='style2'){
                    $ticketHtmlOutput = wxgiveaway_print_ticket_style2($ticketsHtml);
                }
            }


        }


        if (strpos($email_content, '{tickets}') !== false) {
            $email_content = str_replace('{tickets}', $ticketHtmlOutput, $email_content);
        }else{
            $email_content .= $ticketHtmlOutput;
        }

        $customer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
        $customer_email = $order->get_billing_email();
        $customer_phone = $order->get_billing_phone();
        $order_id = $order->get_id();
        $order_date = date_i18n(get_option('date_format'), strtotime($order->get_date_created()));
        $order_number = $order->get_order_number();
        $placeholders = [
            '{customer_name}',
            '{customer_email}',
            '{customer_phone}',
            '{order_id}',
            '{order_date}',
            '{order_number}'
        ];

        $replacements = [
            $customer_name,          // Assume these variables are defined
            $customer_email,
            $customer_phone,
            $order_id,
            $order_date,
            $order_number
        ];

        // Perform the replacement
        $email_content = str_replace($placeholders, $replacements, $email_content);
        echo $email_content; // phpcs:ignore

        wxgiveaway_ticket_email_footer();

        $output = ob_get_clean();

        $output = apply_filters('wxgiveaway_ticket_email_content', $output, $order, $results, $ticket_style, $logo);
        $email_subject = apply_filters('wxgiveaway_ticket_email_subject', $email_subject, $order, $results, $ticket_style, $logo);
        
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>',
            'Reply-To: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>',
            'X-Mailer: PHP/' . phpversion()
        );

        // Send both HTML and plain text
        add_filter('wp_mail_content_type', function() { return 'text/html'; });
        wp_mail($email_to, $email_subject, $output, $headers);
    }


}


function wxgiveaway_print_ticket_style2($ticketsHtml) {
    $settings = get_option('wxgiveaway_settings');
        
    $_winner_email_ticket_label = $settings['_wxgiveaway_winner_email_ticket_label'] ?? __('Ticket', 'giveaway-lottery');
       
    $html= '<h2>' . esc_html($_winner_email_ticket_label) . '</h2><div style="margin-bottom:40px;">
    <table style="border-collapse: separate;
    border-spacing: 0 10px;width: 100%; margin:auto; background: #ffffff;">';
    
    foreach ($ticketsHtml as $ticket) {
        $html.= wp_kses_post($ticket);  // Use wp_kses_post to allow safe HTML content
    }
    
    $html.= '</table></div>';
    return $html;
}

function wxgiveaway_print_ticket_style1($ticketsHtml) {
    $settings = get_option('wxgiveaway_settings');
        
    $_winner_email_ticket_label = $settings['_wxgiveaway_winner_email_ticket_label'] ?? __('Ticket', 'giveaway-lottery');
       
    $html='<h2>' . esc_html($_winner_email_ticket_label) . '</h2><div style="margin-bottom:40px;">
    <table style="border-collapse: separate;border-spacing: 10px;width: 100%; margin:auto; background: #ffffff;">';
    
    for ($i = 0; $i < count($ticketsHtml); $i += 2) {
        $html.= '<tr>';  // Start a new row
        
        // Print the current td
        $html.= wp_kses_post($ticketsHtml[$i]); 
        
        // Check if there is a next td, if not add a blank td
        if (isset($ticketsHtml[$i + 1])) {
            $html.= wp_kses_post($ticketsHtml[$i + 1]);
        } else {
            $html.= '<td style=" margin: 0 auto; text-align: center; width: 50%;"></td>';
        }
        $html.= '</tr>';  // Close the row
    }
    $html.= '</table></div>';

    return $html;
}


// generate ticket html markup
function wxgiveaway_get_ticket_for_selected_style($row,$ticket_style,$order,$logo){
    $ticket='';
    switch ($ticket_style) {
        case 'style1':
            $ticket = wxgiveaway_get_ticket_style1($row,$logo);
            break;
        case 'style2':
            $ticket = wxgiveaway_get_ticket_style2($row,$order,$logo);
            break;
    }

    return $ticket;
}

// ticket style 1 markup
function wxgiveaway_get_ticket_style1($row,$logo){
    $date=$row->meta_value;

    $dateTime = new DateTime($date);
    // get date & time format from settings
    $date_format = get_option('date_format');
    $time_format = get_option('time_format');
    $date_time_format = $date_format.' '.$time_format;
    $ticket_date_time_format = apply_filters('wxgiveaway_ticket_date_time_format',$date_time_format);
    $formattedDate = $dateTime->format($ticket_date_time_format);


	 if (class_exists('Wx_Giveway_Lottry_for_Woocommerce_Pro')) {

        $wx_ticket_prefix = $row->prefix;
        $wx_ticket_sufix = $row->suffix;
        $ticket_no = apply_filters('wxgiveaway_ticket_no',$row->ticket_no,$row);
		$formatted_ticket_no = $wx_ticket_prefix.$ticket_no.$wx_ticket_sufix;
	 }else{
        $ticket_no = apply_filters('wxgiveaway_ticket_no',$row->ticket_no,$row);
		$formatted_ticket_no = $ticket_no;
	 }

    $settings = get_option('wxgiveaway_settings');
        
    $_winner_email_ticket_name_label = $settings['_wxgiveaway_winner_email_ticket_name_title'] ?? __('Giveaway', 'giveaway-lottery');
    $_winner_email_ticket_no = $settings['_wxgiveaway_winner_email_ticket_no'] ??  __('Ticket no', 'giveaway-lottery');
    $_winner_email_ticket_draw_date = $settings['_wxgiveaway_winner_email_ticket_draw_date'] ?? __('Draw Date', 'giveaway-lottery');
   
    $_enable_email_customer_name = $settings['_wxgiveaway_enable_email_customer_name'] ?? 'no';
    $_enable_email_customer_number = $settings['_wxgiveaway_enable_email_phone_number'] ?? 'no';
    $_enable_draw_date_hide = $settings['_wxgiveaway_draw_date_hide'] ?? 'no';
    $_enable__wxgiveaway_title_name_hide = $settings['_wxgiveaway_title_name_hide'] ?? 'no';
    $_wxgiveaway_ticket_number_hide = $settings['_wxgiveaway_ticket_number_hide'] ?? 'no';
       
  


    $ticket = '<td style=" margin: 0 auto; text-align: center; border: 1px solid #ddd; width: 50%; border-radius: 10px;">
    <table>        
        <tr>
            <td style=" margin: 0 auto; text-align: center;">';

        if ($logo) {
            $ticket .= '<img style="max-width:50%; display: block; margin: 0px auto;" src="' . $logo . '" alt="' . __("site logo", "giveaway-lottery") . '">';
        }

        $ticket .= '</td>
                </tr>';
        if ($_enable__wxgiveaway_title_name_hide === 'no') {
                $ticket .= '<tr>
                    <td style="padding: 5px 10px;text-align: center;">
                        <h3 style="text-align: center;font-size: 14px; color: #636363;margin: 0;font-weight: 600;">' . esc_html($_winner_email_ticket_name_label) . ': ' . esc_html($row->post_title) . '</h3>
                    </td>                                                    
                </tr>';
        }

        if ($_wxgiveaway_ticket_number_hide === 'no') {
                $ticket .= '<tr>    
                    <td style="padding: 5px 10px; text-align: center;">
                        <h3 style="font-size: 14px; color: #636363;margin: 0;font-weight: 600;text-align: center;">' . esc_html($_winner_email_ticket_no) . ': ' . esc_html($formatted_ticket_no) . '</h3>
                    </td>
                </tr>'; 
        }

        if ($_enable_draw_date_hide === 'no') {
            $ticket .= '<tr>
                    <td style="padding: 5px 10px;text-align: center;"> 
                        <h3 style="font-size: 14px; color: #636363;margin: 0;font-weight: 600;text-align: center;">' . esc_html($_winner_email_ticket_draw_date) . ': ' . esc_html($formattedDate) . '</h3>
                    </td>
                </tr>';
        }

        $ticket .= '</table>
        </td>';

    return $ticket;

}

// ticket style 2 markup
function wxgiveaway_get_ticket_style2($row,$order,$logo){
    $name=$order->get_billing_first_name().' '.$order->get_billing_last_name();
    $date=$row->meta_value;
    $dateTime = new DateTime($date);
    
    // get date & time format from settings
    $date_format = get_option('date_format');
    $time_format = get_option('time_format');
    $date_time_format = $date_format.' '.$time_format;
    $ticket_date_time_format = apply_filters('wxgiveaway_ticket_date_time_format',$date_time_format);
    $formattedDate = $dateTime->format($ticket_date_time_format);

    if (class_exists('Wx_Giveway_Lottry_for_Woocommerce_Pro')) {
        $wx_ticket_prefix = $row->prefix;
        $wx_ticket_sufix = $row->suffix;
        $ticket_no = apply_filters('wxgiveaway_ticket_no',$row->ticket_no,$row);
		$formatted_ticket_no = $wx_ticket_prefix.$ticket_no.$wx_ticket_sufix;
    }else{
        $ticket_no = apply_filters('wxgiveaway_ticket_no',$row->ticket_no,$row);
        $formatted_ticket_no = $ticket_no;
    }

        $settings = get_option('wxgiveaway_settings');
        
    $_winner_email_ticket_name_label = $settings['_wxgiveaway_winner_email_ticket_name_title'] ?? __('Giveaway', 'giveaway-lottery');
    $_winner_email_ticket_no = $settings['_wxgiveaway_winner_email_ticket_no'] ??  __('Ticket no', 'giveaway-lottery');
    $_winner_email_ticket_draw_date = $settings['_wxgiveaway_winner_email_ticket_draw_date'] ?? __('Draw Date', 'giveaway-lottery');
    $_winner_email_customer_name = $settings['_wxgiveaway_winner_email_customer_name'] ?? __('Name', 'giveaway-lottery');
    $_winner_email_customer_number = $settings['_wxgiveaway_winner_email_phone_number'] ?? __('Number', 'giveaway-lottery');

    $_enable_email_customer_name = $settings['_wxgiveaway_enable_email_customer_name'] ?? 'no';
    $_enable_email_customer_number = $settings['_wxgiveaway_enable_email_phone_number'] ?? 'no';
    $_enable_draw_date_hide = $settings['_wxgiveaway_draw_date_hide'] ?? 'no';
    $_enable__wxgiveaway_title_name_hide = $settings['_wxgiveaway_title_name_hide'] ?? 'no';
    $_wxgiveaway_ticket_number_hide = $settings['_wxgiveaway_ticket_number_hide'] ?? 'no';

       

    $ticket = '<tr>
    <td style="vertical-align:bottom;margin: 10px auto; text-align: left; width: 55%; border-width: 1px 0px 1px 1px; border-color:#ddd; border-style: solid; border-radius: 5px 0px 0px 5px;">
        <table>';

    // Customer name
    if ($_enable_email_customer_name === 'no') {
        $ticket .= '<tr>
            <td style="padding:0px;">
                <h3 style="font-size: 14px; color: #636363;margin: 0;font-weight: 600;">' . 
                esc_html($_winner_email_customer_name) . ': ' . esc_html($name) . '</h3>
            </td>
        </tr>';
    }

    // Customer number
    if ($_enable_email_customer_number === 'no') {
        $ticket .= '<tr>
            <td style="padding:0px;">
                <h3 style="font-size: 14px; color: #636363;margin: 0;font-weight: 600;">' . 
                esc_html($_winner_email_customer_number) . ': ' . esc_html($order->get_billing_phone()) . '</h3>
            </td>
        </tr>';
    }

    // Draw date
    if ($_enable_draw_date_hide === 'no') {
        $ticket .= '<tr>
            <td style="padding:0px;">
                <h3 style="font-size: 14px; color: #636363;margin: 0;font-weight: 600;">' . 
                esc_html($_winner_email_ticket_draw_date) . ': ' . esc_html($formattedDate) . '</h3>
            </td>
        </tr>';
    }

    $ticket .= '</table>
        </td>

        <td style="vertical-align:bottom;margin: 10px auto; width: 45%; border-width: 1px 1px 1px 0px; border-color:#ddd; border-style: solid; border-radius: 0px 5px 5px 0px;">
            <table>
                <tr>
                    <td style="margin: 0 auto; text-align: center;">';

    // Logo
    if ($logo) {
        $ticket .= '<img style="max-width:40%; display: block; margin: 0px auto;" src="' . esc_url($logo) . '" alt="' . esc_attr__("site logo", 'giveaway-lottery') . '">';
    }

    $ticket .= '</td>
                </tr>';

    // Title name
    if ($_enable__wxgiveaway_title_name_hide === 'no') {
        $ticket .= '<tr>
            <td style="padding:0px;text-align:center;">
                <h3 style="font-size: 14px; color: #636363; margin: 0;font-weight: 600;text-align:center;">' . 
                esc_html($_winner_email_ticket_name_label) . ': ' . esc_html($row->post_title) . '</h3>
            </td>
        </tr>';
    }

    // Ticket number
    if ($_wxgiveaway_ticket_number_hide === 'no') {
        $ticket .= '<tr>
            <td style="padding:0px;text-align:center;">
                <h3 style="font-size: 14px; color: #636363;margin: 0;font-weight: 600;text-align:center;">' . 
                esc_html($_winner_email_ticket_no) . ': ' . esc_html($formatted_ticket_no) . '</h3>
            </td>
        </tr>';
    }

    $ticket .= '</table>
        </td>
    </tr>';

    return $ticket;
}

add_filter( 'woocommerce_order_item_display_meta_key', function($display_key, $meta, $item){

    if($display_key=='no_of_tickets'){
        $settings                   = get_option('wxgiveaway_settings');
        $_winner_email_ticket_label = $settings['_wxgiveaway_winner_email_ticket_label'] ?? __('Ticket','giveaway-lottery');
        $display_key                = $_winner_email_ticket_label;
    }
    return $display_key;
},10,3);

// Show tickets in thank you page
add_action('woocommerce_thankyou','wxgiveaway_show_ticket_numbers',10,1);

add_action('woocommerce_view_order','wxgiveaway_show_ticket_numbers',10,1);

function wxgiveaway_show_ticket_numbers($order_id){
    global $wpdb;
    // $table_name = esc_sql($wpdb->prefix . 'wxgiveaway');
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $results=$wpdb->get_results($wpdb->prepare("SELECT a.ticket_no,a.giveaways_id,a.prefix,a.suffix,a.order_id,a.order_item_id,a.variation_id FROM {$wpdb->prefix}wxgiveaway a WHERE a.order_id = %d", $order_id));
    if($results){
        $tickets=array();
        foreach($results as $row){
            $ticket_no = apply_filters('wxgiveaway_ticket_no',$row->ticket_no,$row);
            $tickets[]=[$ticket_no,$row->giveaways_id,$row->prefix,$row->suffix];
        }
       
        if (count($tickets) > 0) {
            echo '<h4>' . esc_html__('Tickets:', 'giveaway-lottery') . '</h4>';
            if (class_exists('Wx_Giveway_Lottry_for_Woocommerce_Pro')) {
                    
                    $formatted_tickets  = array_map(function($ticket) {
                            
                            return esc_html($ticket[2] . $ticket[0] . $ticket[3]);
                        }, $tickets);
            
                    $formatted_tickets = array_map(function($ticket) {
                        return "<span style='background-color: #ddd;' class='ticket_no'>" . esc_html($ticket) . "</span>";
                    },  $formatted_tickets);
                    echo '<div class="thank_you_ticket_wrap">' . wp_kses_post(implode(" ", $formatted_tickets)) . '</div>';

            } else {
                $wrappedArray = array_map(function($item) {
                    return "<span style='background-color: #ddd;' class='ticket_no'>" . esc_html($item[0]) . "</span>";
                }, $tickets);
                echo '<div class="thank_you_ticket_wrap">' . wp_kses_post(implode(" ", $wrappedArray)) . '</div>';
            }
        }
        
    }
}

// add tickets column at my account orders page
function wxgiveaway_add_order_column( $columns ) {
    // Define the new column with its title
    $new_column = array(
        'order-tickets' => esc_html__( 'Tickets', 'giveaway-lottery' )
    );

    // Reorder the columns by inserting the new column after 'order-total'
    $columns = array_slice( $columns, 0, array_search( 'order-actions', array_keys( $columns ) ), true ) +
               $new_column +
               array_slice( $columns, array_search( 'order-actions', array_keys( $columns ) ), NULL, true );

    return $columns;
}
add_filter( 'woocommerce_account_orders_columns', 'wxgiveaway_add_order_column',10,1 );

// Populate the new column with data
function wxgiveaway_my_orders_tickets_column_content( $order ) {
    // Get the order ID
    $order_id = $order->get_id();
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $total_obtain_tickets = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(id) FROM {$wpdb->prefix}wxgiveaway WHERE order_id = %d",
        $order_id
    ) );
    // If no tickets found, display a message
    if ( $total_obtain_tickets === null || $total_obtain_tickets === 0 ) {
        echo esc_html__( 'No tickets', 'giveaway-lottery' );
        return;
    }
    echo esc_html( $total_obtain_tickets );
}
add_action( 'woocommerce_my_account_my_orders_column_order-tickets', 'wxgiveaway_my_orders_tickets_column_content' );

function wxgiveaway_ticket_orderpage($item_id, $item) {
    global $wpdb;

    // Check if $item is valid
    if (!is_object($item) || !method_exists($item, 'get_order_id')) {
        return;
    }

    // Get order ID
    $order_id = $item->get_order_id();
    if (empty($order_id)) {
        return;
    }

    $table_name = $wpdb->prefix . 'wxgiveaway';

    // phpcs:ignore
    $query = $wpdb->prepare("SELECT ticket_no, prefix, suffix,order_id,giveaways_id,variation_id,order_item_id FROM {$table_name} WHERE order_id = %d AND order_item_id = %d",$order_id, $item_id);

    // phpcs:ignore
    $tickets = $wpdb->get_results($query, ARRAY_A);
    
    if (!empty($tickets)) {
        if (class_exists('Wx_Giveway_Lottry_for_Woocommerce_Pro')) {
            $formatted_tickets = array_map(function($ticket) {
                $ticket_no = apply_filters('wxgiveaway_ticket_no',$ticket['ticket_no'],$ticket);
                $ticket_number = esc_html($ticket['prefix'] . $ticket_no . $ticket['suffix']);
                return "<span style='background-color: #ddd; padding: 2px 5px; border-radius: 3px;' class='ticket_no'>" . $ticket_number . "</span>";
            }, $tickets);
            
            // Filter out any empty strings from the array
            $formatted_tickets = array_filter($formatted_tickets);
            
            if (!empty($formatted_tickets)) {
                echo '<div class="order_ticket_no">' . wp_kses_post(implode(' ', $formatted_tickets)) . '</div>';
            }
        } else {
            $ticket_numbers = array_map(function($ticket) {
                $ticket_no = apply_filters('wxgiveaway_ticket_no',$ticket['ticket_no'],$ticket);
                return isset($ticket_no) ? esc_html($ticket_no) : '';
            }, $tickets);
            
            $ticket_numbers = array_filter($ticket_numbers);
            
            if (!empty($ticket_numbers)) {
                // phpcs:ignore
                echo '<p>' . implode(', ', $ticket_numbers) . '</p>';
            }
        }
    } else {
        echo '<p><strong>' . esc_html__('Ticket Numbers:', 'giveaway-lottery') . '</strong> ' . esc_html__('No tickets found.', 'giveaway-lottery') . '</p>';
    }
}

// Hook to show the ticket list in the WooCommerce order edit page
add_action('woocommerce_after_order_itemmeta', 'wxgiveaway_ticket_orderpage', 10, 2);

function wxgiveaway_ticket_email_header(){
    ?>
    <!DOCTYPE html>
    <html <?php language_attributes(); ?>>
        <head>
            <meta http-equiv="Content-Type" content="text/html; charset=<?php bloginfo( 'charset' ); ?>" />
            <meta content="width=device-width, initial-scale=1.0" name="viewport">
            <title><?php
                // phpcs:ignore
                echo get_bloginfo( 'name', 'display' ); ?>
            </title>
        </head>
        <body <?php echo is_rtl() ? 'rightmargin' : 'leftmargin'; ?>="0" marginwidth="0" topmargin="0" marginheight="0" offset="0">
		<table width="100%" id="outer_wrapper">
			<tr>
				<td><!-- Deliberately empty to support consistent sizing and layout across multiple email clients. --></td>
				<td width="600">
					<div id="wrapper" dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>">
						<table border="0" cellpadding="0" cellspacing="0" height="100%" width="100%" id="inner_wrapper">
							<tr>
								<td align="center" valign="top">
									
									<table border="0" cellpadding="0" cellspacing="0" width="100%" id="template_container">
										
										<tr>
											<td align="center" valign="top">
												<!-- Body -->
												<table border="0" cellpadding="0" cellspacing="0" width="100%" id="template_body">
													<tr>
														<td valign="top" id="body_content">
															<!-- Content -->
															<table border="0" cellpadding="20" cellspacing="0" width="100%">
																<tr>
																	<td valign="top" id="body_content_inner_cell">
																		<div id="body_content_inner">
    <?php
}

function wxgiveaway_ticket_email_footer(){
    ?>
            </div>
                                                                        </td>
                                                                    </tr>
                                                                </table>
                                                                <!-- End Content -->
                                                            </td>
                                                        </tr>
                                                    </table>
                                                    <!-- End Body -->
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </td>
                    <td><!-- Deliberately empty to support consistent sizing and layout across multiple email clients. --></td>
                </tr>
            </table>
        </body>
    </html>
    <?php
}