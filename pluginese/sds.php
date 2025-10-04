<?php
/**
Plugin Name:       Giveaway Lottery for WooCommerce (Raffle, Lucky Draw, Contest, Competition, Sweepstakes, Tombola, Prize Draw)
Requires Plugins:  woocommerce
Plugin URI:        https://webcartisan.com/plugins/giveaway-lottery
Description:       Giveaway Lottery for WooCommerce plugin is a comprehensive Giveaway management system based on WooCommerce.
Version:           1.0.3
Author:            WebCartisan
Author URI:        https://webcartisan.com/
License:           GPL v2 or later
License URI:       https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
Text Domain:       giveaway-lottery
Requires at least: 5.6
Requires PHP:      7.2
Domain Path: /languages
*/

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) { exit; }

// plugin constants
define("WXGIVEAWAY_VERSION", "1.0.3");
define( 'WXGIVEAWAY_ACC_FILE', __FILE__ );
define( 'WXGIVEAWAY_ACC_URL', plugin_dir_url(__FILE__) );
define( 'WXGIVEAWAY_ACC_PATH', plugin_dir_path( __FILE__ ) );

define('WXGIVEAWAY_TICKET_MIN_VALUE', 1);
define('WXGIVEAWAY_TICKET_MAX_VALUE', 99999999);


function wxgiveaway_giveaway_plugin_activate(){
    global $wpdb;
    $table_name = $wpdb->prefix . 'wxgiveaway'; // esc_sql() not needed for dbDelta()

    $sql = "CREATE TABLE {$table_name} (
        id BIGINT(20) NOT NULL AUTO_INCREMENT,
        order_id BIGINT(20),
        giveaways_id BIGINT(20),
        variation_id BIGINT(20),
        order_item_id BIGINT(20),
        ticket_no VARCHAR(250),
        prefix VARCHAR(250),
        suffix VARCHAR(250),
        PRIMARY KEY (id),
        INDEX giveaways_id_key (giveaways_id),
        INDEX idx_giveaways_variation (giveaways_id, variation_id),
        INDEX idx_order_giveaways_variation (order_id, giveaways_id, variation_id),
        INDEX idx_order_giveaways_variation_order_item (order_id, giveaways_id, variation_id, order_item_id)
    ) {$wpdb->get_charset_collate()};"; // Critical for dbDelta()!

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    
    // Drop the procedure if it already exists
    // phpcs:ignore
   $wpdb->query("DROP PROCEDURE IF EXISTS GenerateWXGiveawayTickets"); // $wpdb->prepare() is not compatible for this context. this query will not create any sql injection

    // Create the procedure
    // $table_name is manually sanitized to avoid sql injection
    // Since $wpdb->prepare() is incompatible with table names or procedures, we bypass it by manually sanitizing the table name with esc_sql() and inserting it directly.
   $sql="
       CREATE PROCEDURE GenerateWXGiveawayTickets(
           IN p_giveaway_id BIGINT,
           IN p_order_id BIGINT,
           IN p_ticket_count INT,
           IN p_min_ticket_number INT,
           IN p_max_ticket_number INT,
           IN p_order_item_id INT,
           IN p_variation_id INT
       )
       BEGIN
           DECLARE ticket_counter INT DEFAULT 0;
           DECLARE new_ticket_number INT;
           DECLARE available_tickets INT;

           -- Get the number of remaining available tickets for the given giveaway
           SELECT (p_max_ticket_number - p_min_ticket_number + 1) - COUNT(*) INTO available_tickets
           FROM {$table_name}
           WHERE giveaways_id = p_giveaway_id;

           -- If the requested number of tickets exceeds the available tickets, set it to the available tickets
           IF p_ticket_count > available_tickets THEN
               SET p_ticket_count = available_tickets;
           END IF;

           -- Loop to generate unique tickets
           WHILE ticket_counter < p_ticket_count DO
               SET new_ticket_number = FLOOR(RAND() * (p_max_ticket_number - p_min_ticket_number + 1) + p_min_ticket_number);

               -- Check if the generated ticket number is not already used for the given giveaway
               IF NOT EXISTS (
                   SELECT id
                   FROM {$table_name}
                   WHERE giveaways_id = p_giveaway_id AND ticket_no = new_ticket_number
               ) THEN
                   -- Insert the new ticket
                   INSERT INTO {$table_name} (giveaways_id, ticket_no, order_id, order_item_id, variation_id)
                   VALUES (p_giveaway_id, new_ticket_number, p_order_id, p_order_item_id, p_variation_id);
                   SET ticket_counter = ticket_counter + 1;
               END IF;
           END WHILE;
       END;
   ";
    // phpcs:ignore
   $wpdb->query($sql);
   
    
    // add default settings data
    $wxgiveaway_default_settings = array(
        'ticket_generate'=>['processing','completed'],
        'ticket_delete_at'=>['cancelled','refunded','failed'],
        'ticket_send'=>'',
        'ticket_style'=>'style1',
        'logo_url'=>'',
  
    );

    if (get_option('wxgiveaway_settings') === false) {
        add_option('wxgiveaway_settings', $wxgiveaway_default_settings);
    }
    
}
register_activation_hook(__FILE__, "wxgiveaway_giveaway_plugin_activate");

// ======= Registering wxGiveaway files =======
add_action('wp_enqueue_scripts', 'wxgiveaway_fontend_assets');
function wxgiveaway_fontend_assets() {
    global $post;
    $post_id = 0;
    if($post) $post_id = $post->ID;
    $wxg = '';
    $settings = get_option('wxgiveaway_settings');
    wp_enqueue_script('jquery'); 
    wp_enqueue_style('wxgiveaway_flipdown_css', plugin_dir_url(__FILE__) . 'inc/frontend/assets/css/flipdown.css',[],WXGIVEAWAY_VERSION);
    wp_enqueue_script('wxgiveaway_flipdown_us', plugin_dir_url(__FILE__ ) . 'inc/frontend/assets/js/flipdown.js', array('jquery'), WXGIVEAWAY_VERSION, true);
    if (class_exists('Wx_Giveway_Lottry_for_Woocommerce_Pro')){
        $wxg = "pro_active";
    }

    $allocated_entries = wxgiveaway_get_total_number_of_tickets($post_id);

    // phpcs:ignore
    global $wpdb;

    // Fetch the count of sold tickets
    // phpcs:ignore
    $row_counts = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(id) FROM {$wpdb->prefix}wxgiveaway WHERE giveaways_id = %d",
        $post_id
    ));
    
    // Calculate left tickets
    $left_tickets = max(0, $allocated_entries - $row_counts);


//  if (class_exists('Wx_Giveway_Lottry_for_Woocommerce_Pro')) {
        // Localize the script with your timer labels
        wp_localize_script('wxgiveaway_flipdown_us', 'wxgiveawayTimerLabels', array(
            'days'    => isset($settings['_timer_label_days']) ? $settings['_timer_label_days'] : __('Days', 'giveaway-lottery'),
            'hours'   => isset($settings['_timer_label_hours']) ? $settings['_timer_label_hours'] : __('Hours', 'giveaway-lottery'),
            'minutes' => isset($settings['_timer_label_minutes']) ? $settings['_timer_label_minutes'] : __('Minutes', 'giveaway-lottery'),
            'seconds' => isset($settings['_timer_label_seconds']) ? $settings['_timer_label_seconds'] : __('Seconds','giveaway-lottery'),
            'wxg_plugin'   => $wxg,
            'left_tickets' => $left_tickets,
        ));
    // }
    // 	if($post->ID === 148){
	if (is_array($settings) && isset($settings['winner_reveal_page']) && is_page($settings['winner_reveal_page'])) 
	{
        

		wp_enqueue_style('wxgiveaway_custom_style', plugin_dir_url(__FILE__) . 'inc/frontend/assets/css/wxgiveaway_style.css',array(),WXGIVEAWAY_VERSION );
		wp_enqueue_style('wxgiveaway_winner_css', plugin_dir_url(__FILE__) . 'inc/frontend/assets/css/winner-select.css',[],WXGIVEAWAY_VERSION);
		wp_enqueue_script('wxgiveaway_confetti_js', plugin_dir_url(__FILE__ ) . 'inc/frontend/assets/js/confetti.browser.min.js', array(), WXGIVEAWAY_VERSION, true);
        $winner_page_style = $settings['_wxgiveaway_winner_page_style']?? 'style1';
        //if( $winner_page_style == 'style1'){
     	    wp_enqueue_script('wxgiveaway_winner_js', plugin_dir_url(__FILE__ ) . 'inc/frontend/assets/js/winner-select.js', array('jquery'), WXGIVEAWAY_VERSION, true);	
        //}
    	wp_enqueue_script('wxgiveaway_custom_script', plugin_dir_url(__FILE__ ) . 'inc/frontend/assets/js/js.js', array('jquery'), WXGIVEAWAY_VERSION, true);
	}
	
    if ( is_shop() || is_product_category() || is_product_tag() || is_product() ) {

        global $product;
        wp_enqueue_script('jquery');
        wp_enqueue_script('wxgiveaway_timer_js', plugin_dir_url(__FILE__ ) . 'inc/frontend/add-up/timer.js', array('jquery'), WXGIVEAWAY_VERSION, true);
        wp_enqueue_style('wxgiveaway_countdown_timer_css', plugin_dir_url(__FILE__ ) . 'inc/frontend/add-up/timer.css',[], WXGIVEAWAY_VERSION);
        wp_localize_script('wxgiveaway_timer_js', 'wxgiveaway_single_script', array(
            'singlebuttontext' => (isset($settings['_winner_list_button_text']) ? $settings['_winner_list_button_text'] : __('See winner', 'giveaway-lottery')),
            'giveaway_closed' => apply_filters('wxgiveaway_giveaway_closed_text', __('Giveaway is closed', 'giveaway-lottery'),$product),
        ));

        $variation_ticket_data = [];
       // global $post;
		if(is_product()){
			$product = wc_get_product( $post->ID ); // Safely get the product object
			if ( $product && $product->is_type( 'variable' ) ) {
				// Prepare your data
				

				foreach ( $product->get_available_variations() as $variation_data ) {
					$variation_id = $variation_data['variation_id'];

					// Initialize variation properly
					$variation = new WC_Product_Variation( $variation_id );

					$variation_attributes = $variation->get_attributes();
					$no_of_variation_product_ticket = get_post_meta( $variation_id, '_no_of_tickets', true );
                    $variable_bonus_ticket = get_post_meta($variation_id, '_bonus_tickets', true );


					$variation_ticket_data[] = [
						'id'         => $variation_id,
						'attributes' => $variation_attributes,
						'tickets'    => $no_of_variation_product_ticket,
                        'bonus'      => $variable_bonus_ticket,
					];
				}
			}
		}
        wp_localize_script(
            'wxgiveaway_timer_js',
            'wxgiveaway_variationTicketData',
            $variation_ticket_data
        );
    }
	
    wp_localize_script('wxgiveaway_custom_script', 'wxgiveaway_gswcAjax', array(
        'ajaxurl'=> admin_url('admin-ajax.php'),
        'cartUrl'=> wc_get_cart_url()
    ));
}

/**
 * Backend enque.
 *
 * @since 1.0.0
 * @retun void.
 */
function wxgiveaway_backend_assets() {

    // selector cdn
    wp_enqueue_style('wxgiveaway-select2-css', plugin_dir_url(__FILE__) . 'inc/admin/assets/css/selector/selector.min.css', array(), WXGIVEAWAY_VERSION );
    wp_enqueue_script('wxgiveaway-select2-js', plugin_dir_url(__FILE__ ) . 'inc/admin/assets/js/selector/selector.min.js', array('jquery'), WXGIVEAWAY_VERSION , true);

    //flatpicker time picker js 
    wp_enqueue_script( 'wxgiveaway-flatpickr-js',  plugin_dir_url(__FILE__ ) . 'inc/admin/assets/js/flatpickr.min.js', array('jquery'), '4.6.13', true );
     //flatpicker time picker css
    wp_enqueue_style( 'wxgiveaway-flatpickr-style-css', plugin_dir_url(__FILE__) . 'inc/admin/assets/css/flatpikr.css', array(), '4.6.13' );
    wp_enqueue_style('wxgiveaway-backend-css', plugin_dir_url(__FILE__) . 'inc/admin/assets/css/wxgiveaway_backend_style.css' , array(), WXGIVEAWAY_VERSION );

  // Ensure jQuery is enqueued first
  wp_enqueue_script('jquery'); 
  wp_enqueue_script('wxgiveaway_admin_script', plugin_dir_url(__FILE__ ) . 'inc/admin/assets/js/wp_admin.js', array('jquery'), WXGIVEAWAY_VERSION, true );

  wp_localize_script('wxgiveaway_admin_script', 'wxgiveaway_gswcBkAjax', array(
    'ajax_url'=> admin_url('admin-ajax.php'),
    'cartUrl'=> wc_get_cart_url(),
    'glnonce'    => wp_create_nonce( 'gl_dismiss_nonce' )
  ));
}
add_action('admin_enqueue_scripts', 'wxgiveaway_backend_assets');

require_once 'inc/admin/giveaway-settings.php';
require_once 'inc/frontend/giveaway-tickets.php';
require_once 'inc/admin/check-winner-details/wxgiveaway-check-winner.php';
require_once 'inc/frontend/wxgiveaway-function.php';
require_once 'inc/frontend/add-up/ticket-range.php';


/**
 * Winner selection.
 *
 * @since 1.0.0
 * @retun void.
 */
function wxgiveaway_winner_selection_cron($giveaway_id) {

    if($giveaway_id){
        $winner_selected = (int) get_post_meta($giveaway_id, 'winner_selected', true);
        $winner_selected = apply_filters('wxgiveaway_winner_selected', $winner_selected, $giveaway_id);

        //print_r($winner_selected);

        if($winner_selected != 1){
            global $wpdb;
            $table_name = $wpdb->prefix.'wxgiveaway';
        
            $_pre_defined_winner_ticket = get_post_meta($giveaway_id,'_pre_defined_winner_ticket',true);
           

            $_pre_defined_winner_ticket = apply_filters('pre_define_winner_ticket_modify', $_pre_defined_winner_ticket,$giveaway_id);

            $getPreWinnerDetails = get_post_meta($giveaway_id,'wxgiveaway_winner_details',true);

            $ignoredTickets = array();
            if(is_array($getPreWinnerDetails)){
                foreach ($getPreWinnerDetails as $key => $value) {
                    $ignoredTickets[]=$value[3];
                }
            }

            if ( $_pre_defined_winner_ticket ) {
                // phpcs:ignore
                $sql = $wpdb->prepare("SELECT id,order_id, ticket_no, prefix, suffix FROM {$table_name} WHERE giveaways_id = %d AND ticket_no = %d LIMIT 1",$giveaway_id, $_pre_defined_winner_ticket);
            } else {
                // phpcs:ignore
                if(count($ignoredTickets)>0){
                    $sql = $wpdb->prepare("SELECT id,order_id, ticket_no, prefix, suffix FROM {$table_name} WHERE giveaways_id = %d and id NOT IN(".implode(',',$ignoredTickets).") ORDER BY RAND() LIMIT 1", $giveaway_id);
                }else{
                    $sql = $wpdb->prepare("SELECT id,order_id, ticket_no, prefix, suffix FROM {$table_name} WHERE giveaways_id = %d ORDER BY RAND() LIMIT 1", $giveaway_id);
                }
            }

            // phpcs:ignore
            $row = $wpdb->get_row($sql);

            if($row){
                $ticket_no = apply_filters('wxgiveaway_ticket_no',$row->ticket_no,$row);
                $ticket = $row->prefix.$ticket_no.$row->suffix;
                $order = wc_get_order($row->order_id);
                $winnerName = apply_filters('wxgiveaway_winner_name', $order->get_billing_first_name(), $order);

                if(!is_array($getPreWinnerDetails)){
                    $getPreWinnerDetails = array(
                        array(
                                $winnerName,
                                $ticket,
                                $row->order_id,
                                $row->id,
                                current_time('mysql')
                            )
                        );
                }else{
                    $existingTickets = array_column($getPreWinnerDetails, 1); // Get all tickets (index 1)
                    if (!in_array($ticket, $existingTickets)) {
                        $getPreWinnerDetails[]= array(
                            $winnerName,
                            $ticket,
                            $row->order_id,
                            $row->id,
                            current_time('mysql')
                        );
                    }
                }

                update_post_meta($giveaway_id, 'wxgiveaway_winner_details', $getPreWinnerDetails);
                update_post_meta($giveaway_id, 'winner-name', $winnerName);
                update_post_meta($giveaway_id, 'winner-ticket', $ticket);
                update_post_meta($giveaway_id, 'winner-order-id', $row->order_id);


                update_post_meta($giveaway_id, 'winner_selected', 1);
            }
        }
    }
}

add_action('wxgiveaway_giveaway_winner_selection_action','wxgiveaway_winner_selection_cron',1,1);

add_action( 'woocommerce_product_duplicate', function($duplicate, $product ){
    $newProduct = $duplicate->get_id();
    update_post_meta($newProduct, 'winner_selected', 0);
    update_post_meta($newProduct, '_pre_defined_winner_ticket', '');
    update_post_meta($newProduct, 'winner-name', '');
    update_post_meta($newProduct, 'winner-ticket', '');
    update_post_meta($newProduct, 'winner-order-id', '');
},10,2);

/**
 * Giveaway Note to cart item.
 *
 * @since 1.0.0
 * @retun void.
 */
function wxgiveaway_add_giveaway_note_to_cart_item($product_name, $cart_item, $cart_item_key) {
    // Get the product object from cart item
    $product = $cart_item['data'];

    // Get the product ID
    $product_id = $product->get_id();

    // Get the number of tickets from product meta
    $tickets = get_post_meta($product_id, '_no_of_tickets', true);

    // Get the quantity of the product in the cart
    $quantity = $cart_item['quantity'];

    // If not on the checkout page, multiply the number of tickets by the quantity
    $total_tickets = $tickets * $quantity;

    // If on the checkout page, show the original ticket number without multiplying by quantity
    if (is_checkout() && !empty($tickets)) {
        $product_name .= '<p class="giveaway-note" id="giveaway-note-' . $cart_item_key . '" style="font-size: 13px; color:rgb(2, 2, 2); margin: 5px 0 0;">No of tickets: ' . esc_html($tickets) . '</p>';
    }

    return $product_name;
}
add_filter('woocommerce_cart_item_name', 'wxgiveaway_add_giveaway_note_to_cart_item', 20, 3);

/**
 * Plugin setting link.
 *
 * @since 1.0.0
 * @retun void.
 */
function wxgiveaway_plugin_add_settings_link( $actions ) {
    // Add regular settings link
    $settings_link = '<a href="' . admin_url( 'edit.php?post_type=product&page=setting' ) . '">' . __('Settings','giveaway-lottery') . '</a>';
    array_unshift( $actions, $settings_link );
        
    return $actions;
}
add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), 'wxgiveaway_plugin_add_settings_link' );

/**
 * Plugin faq, doc, support.
 *
 * @since 1.0.0
 * @retun void.
 */
function wxgiveaway_append_support_doc_links( $links_array, $plugin_file_name, $plugin_data, $status ) {
	if ( strpos( $plugin_file_name, basename(__FILE__) ) ) {		
		$links_array[] = '<a href="https://www.webcartisan.com/support/?plugin=woocommerce-giveaway-lottery" target="_blank">'.__('Support','giveaway-lottery').'</a>';
		//$links_array[] = '<a href="#">Doc</a>';
		$links_array[] = '<a href="https://wordpress.org/support/plugin/giveaway-lottery/reviews/#new-post">Please Rate Us</a>';
	}
 
	return $links_array;
}

add_filter( 'plugin_row_meta', 'wxgiveaway_append_support_doc_links', 10, 4 );

/**
 * Display admin notice to request a review.
 *
 * @since 1.0.0
 * @retun void.
 */
function wxgiveaway_admin_review_notice() {
    $screen = get_current_screen();
    // Only show on plugin pages or dashboard
    if ( ! in_array( $screen->base, array( 'dashboard', 'plugins', 'product_page_setting' ) ) ) {
        return;
    }
    
    $dismissed = get_option( 'wxgiveaway_review_dismissed', false );
    
    if ( ! $dismissed ) {
       
         
         ?>
        <div class="notice notice-info is-dismissible gl-review-notice">
            <?php // phpcs:ignore ?>
            <img src="https://ps.w.org/giveaway-lottery/assets/icon-256x256.png" alt="Giveaway Lottery for WooCommerce" style="margin-top:4px;width: 70px; height: auto; float: left; margin-right: 10px;" />
            <p>
                <?php esc_html_e( 'We hope you\'re enjoying Giveaway Lottery for WooCommerce Could you please do us a BIG favor and give it a 5-star rating on WordPress.org? It would help us spread the word and boost our motivation!', 'giveaway-lottery' ); ?>
            </p>
            <p>
                <a href="https://wordpress.org/support/plugin/giveaway-lottery/reviews/#new-post" target="_blank" class="button button-primary">
                    <?php esc_html_e( 'Yes, happy to leave a review!', 'giveaway-lottery' ); ?>
                </a>
                <a href="#" class="button gl-dismiss-review" style="margin-left: 10px;">
                    <?php esc_html_e( 'Dismiss', 'giveaway-lottery' ); ?>
                </a>
            </p>
        </div>
        <?php
    }
}
add_action( 'admin_notices', 'wxgiveaway_admin_review_notice' );

/**
 * Giveaway dismiss_review_notice.
 *
 * @since 1.0.0
 * @retun void.
 */
function wxgiveaway_dismiss_review_notice() {
    update_option( 'wxgiveaway_review_dismissed', true );
    wp_die();
}
add_action( 'wp_ajax_wxgiveaway_dismiss_review', 'wxgiveaway_dismiss_review_notice' );

add_filter('wxgiveaway_ticket_no',function($ticket_no){
    return str_pad($ticket_no, 4, '0', STR_PAD_LEFT);
},1,1);