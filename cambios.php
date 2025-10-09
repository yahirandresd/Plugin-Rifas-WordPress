<?php
/*
Plugin Name: Números de Rifa WooCommerce
Description: Permite al cliente elegir números de rifa en la página de producto, los guarda en el pedido y valida que no se repitan.
Version: 1.1
Author: Yahir Andres Rangel Dueñas - NetVuk Interactive 
*/

/* 1. Campo de selección de números con popup mejorado */
add_action('woocommerce_before_add_to_cart_quantity', 'rifa_custom_number_field_popup');
function rifa_custom_number_field_popup() {
    ?>
    <div class="rifa-numbers" style="margin:15px 0;padding:10px;border:1px solid #ddd;">
        <label><strong>Selecciona tu opción:</strong></label>
        <p style="margin:8px 0;">
            <button type="button" class="rifa-option" data-option="random">Aleatorio</button>
            <button type="button" class="rifa-option" data-option="choose">Elegir</button>
        </p>
    </div>

    <style>
        #rifa_popup { position: fixed; top:50%; left:50%; transform:translate(-50%,-50%); background:#fff; border:1px solid #ccc; border-radius:8px; max-width:90%; max-height:80%; z-index:9999; display:flex; flex-direction:column; box-shadow:0 4px 10px rgba(0,0,0,0.2);}
        #rifa_popup_header { display:flex; justify-content:space-between; align-items:center; padding:10px 15px; border-bottom:1px solid #eee; font-size:18px; font-weight:bold;}
        #rifa_popup_close { cursor:pointer; font-size:20px; font-weight:bold; background:none; border:none;}
        #rifa_numbers_container { padding:10px 15px; overflow-y:auto; flex:1 1 auto; display:flex; flex-wrap:wrap; gap:5px;}
        .rifa-number { padding:8px 12px; border:1px solid #ccc; border-radius:5px; cursor:pointer; user-select:none;}
        .rifa-number.selected { background-color:#0073aa; color:#fff; border-color:#005177;}
        #rifa_popup_footer { padding:10px 15px; border-top:1px solid #eee; display:flex; justify-content:flex-end; flex-shrink:0; flex-direction:column; }
        #add_rifa_to_cart { background-color:#0073aa; color:#fff; border:none; padding:8px 15px; border-radius:5px; cursor:pointer; }
        #rifa_error_msg { color:red; text-align:center; margin-top:10px; font-weight:bold; display:none; }
    </style>

    <script type="text/javascript">
        jQuery(document).ready(function($){
            $('.rifa-option').click(function(){
                var option = $(this).data('option');
                if(option === 'choose'){
                    $('.single_add_to_cart_button').hide();
                    if($('#choose_rifa_btn').length === 0){
                        $('<button type="button" id="choose_rifa_btn" class="button">Elegir Números</button>').insertAfter('.single_add_to_cart_button');
                    }
                } else {
                    $('.single_add_to_cart_button').show();
                    $('#choose_rifa_btn').remove();
                    $('<input type="hidden" name="rifa_random" value="1">').appendTo('form.cart');
                }
            });

            $(document).on('click','#choose_rifa_btn', function(){
                // Obtener cantidad desde el span
                var cantidad = parseInt($('#ticket-count-in-single-simple-product').data('ticket') || $('#ticket-count-in-single-simple-product').text());

                $.ajax({
                    url:"<?php echo admin_url('admin-ajax.php'); ?>",
                    method:"POST",
                    data:{ action:'rifa_get_available_numbers', product_id: <?php echo get_the_ID(); ?> },
                    success:function(data){
                        var popup = '<div id="rifa_popup">';
                        popup += '<div id="rifa_popup_header">Elige tus números <button id="rifa_popup_close">&times;</button></div>';
                        popup += '<div id="rifa_numbers_container">';
                        data.numbers.forEach(function(num){
                            popup += '<button class="rifa-number" data-num="'+num+'">'+num+'</button> ';
                        });
                        popup += '</div>';
                        popup += '<div id="rifa_error_msg">Debes seleccionar '+cantidad+' número(s).</div>';
                        popup += '<div id="rifa_popup_footer"><button id="add_rifa_to_cart" disabled>Agregar al carrito</button></div>';
                        popup += '</div>';
                        $('body').append(popup);
                    }
                });
            });

            $(document).on('click','#rifa_popup_close', function(){ $('#rifa_popup').remove(); });

            $(document).on('click','.rifa-number', function(){
                $(this).toggleClass('selected');
                var selected_count = $('#rifa_numbers_container .rifa-number.selected').length;
                var cantidad = parseInt($('#ticket-count-in-single-simple-product').data('ticket') || $('#ticket-count-in-single-simple-product').text());
                if(selected_count === cantidad){
                    $('#add_rifa_to_cart').prop('disabled', false);
                    $('#rifa_error_msg').hide();
                } else {
                    $('#add_rifa_to_cart').prop('disabled', true);
                    $('#rifa_error_msg').show();
                }
            });

            // Agregar al carrito
            $(document).on('click','#add_rifa_to_cart', function(){
                var selected = [];
                $('#rifa_numbers_container .rifa-number.selected').each(function(){
                    selected.push($(this).data('num'));
                });

                var cantidad = parseInt($('#ticket-count-in-single-simple-product').data('ticket') || $('#ticket-count-in-single-simple-product').text());
                if(selected.length != cantidad){
                    $('#rifa_error_msg').show();
                    return;
                }

                if($('#rifa_numbers_input').length===0){
                    $('<input type="hidden" id="rifa_numbers_input" name="rifa_numbers">').appendTo('form.cart');
                }
                $('#rifa_numbers_input').val(selected.join(', '));
                $('.single_add_to_cart_button').show();
                $('#rifa_popup').remove();
            });
        });
    </script>
    <?php
}

/* 2. Validar que los números no estén repetidos (por producto) */
add_filter('woocommerce_add_to_cart_validation', 'rifa_validate_numbers', 10, 2);
function rifa_validate_numbers($passed, $product_id)
{
    if (isset($_POST['rifa_numbers']) && !empty($_POST['rifa_numbers'])) {
        global $wpdb;

        // Números elegidos por el usuario -> normalizar a enteros
        $numbers = array_filter(array_map('trim', explode(',', sanitize_text_field($_POST['rifa_numbers']))), 'strlen');
        $numbers = array_map('intval', $numbers);

        foreach ($numbers as $num) {
            // Buscar si ese número ya fue comprado para este mismo producto (coincidencia exacta)
            $exists = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(im.meta_id)
                FROM {$wpdb->prefix}woocommerce_order_items oi
                INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta im ON oi.order_item_id = im.order_item_id
                INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta pm ON pm.order_item_id = oi.order_item_id AND pm.meta_key = '_product_id'
                WHERE im.meta_key = 'Números de la rifa'
                AND FIND_IN_SET(%s, REPLACE(im.meta_value, ' ', '')) > 0
                AND pm.meta_value = %d
            ", (string)$num, $product_id));

            if ($exists > 0) {
                wc_add_notice(sprintf("El número <strong>%s</strong> ya fue comprado para esta rifa. Por favor elige otro.", esc_html($num)), 'error');
                return false;
            }
        }
    }
    return $passed;
}

/* 3. Guardar los números en el carrito (normalizados, sin espacios) */
add_filter('woocommerce_add_cart_item_data', 'rifa_add_cart_item_data', 10, 2);
function rifa_add_cart_item_data($cart_item_data, $product_id)
{
    if (isset($_POST['rifa_numbers']) && !empty($_POST['rifa_numbers'])) {
        // Sanear, separar, convertir a enteros y volver a unir sin espacios
        $raw = sanitize_text_field($_POST['rifa_numbers']);
        $parts = array_filter(array_map('trim', explode(',', $raw)), 'strlen');
        $parts = array_map('intval', $parts); // fuerza enteros
        $parts = array_map('strval', $parts); // a string nuevamente
        $cart_item_data['rifa_numbers'] = implode(',', $parts); // ejemplo: "1,5,23"
    }
    return $cart_item_data;
}

/* 4. Mostrar en carrito y checkout */
add_filter('woocommerce_get_item_data', 'rifa_display_cart_item_data', 10, 2);
function rifa_display_cart_item_data($item_data, $cart_item)
{
    if (isset($cart_item['rifa_numbers'])) {
        $item_data[] = array(
            'name' => 'Números de la rifa',
            'value' => $cart_item['rifa_numbers'],
        );
    }
    return $item_data;
}


/* 5. Guardar en los detalles del pedido */
add_action('woocommerce_checkout_create_order_line_item', 'rifa_add_order_item_meta', 10, 4);
function rifa_add_order_item_meta($item, $cart_item_key, $values, $order)
{
    if (isset($values['rifa_numbers'])) {
        $item->add_meta_data('Números de la rifa', $values['rifa_numbers'], true);
    }
    if (isset($values['rifa_random'])) {
        $item->add_meta_data('Números aleatorios', 'Sí', true);
    }
}

/* 6. Obtener rango definido en WooCommerce Lottery (0 a n-1) */
function rifa_get_lottery_range($product_id)
{
    $range_meta = get_post_meta($product_id, '_ticket_range', true);

    if (!empty($range_meta) && strpos($range_meta, '-') !== false) {
        list($min, $max) = explode('-', $range_meta);
        $min = intval(trim($min)) - 1;
        $max = intval(trim($max)) - 1; // restamos 1 para que sea 0 a n-1
    } else {
        $min = 0;
        $max = 9; // 100 números: 0-99
    }

    return array('min' => $min, 'max' => $max);
}

/* 7. Generar números aleatorios disponibles (por producto) */
function rifa_generate_random_numbers($product_id, $cantidad = 1)
{
    global $wpdb;
    $range = rifa_get_lottery_range($product_id);

    // Obtener todos los meta_value de 'Números de la rifa' para este product_id
    $ocupados_raw = $wpdb->get_col($wpdb->prepare("
        SELECT im.meta_value
        FROM {$wpdb->prefix}woocommerce_order_items oi
        INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta im ON oi.order_item_id = im.order_item_id
        INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta pm ON pm.order_item_id = oi.order_item_id AND pm.meta_key = '_product_id'
        WHERE im.meta_key = 'Números de la rifa'
        AND pm.meta_value = %d
    ", $product_id));

    // Normalizar: separar todos los strings en números enteros
    $ocupados = [];
    foreach ($ocupados_raw as $meta) {
        if (strlen(trim($meta)) === 0) continue;
        $parts = array_filter(array_map('trim', explode(',', $meta)), 'strlen');
        foreach ($parts as $p) {
            $ocupados[] = intval($p);
        }
    }
    $ocupados = array_values(array_unique($ocupados)); // enteros únicos

    $disponibles = [];
    for ($i = $range['min']; $i <= $range['max']; $i++) {
        if (!in_array($i, $ocupados, true)) {
            $disponibles[] = $i;
        }
    }

    shuffle($disponibles);
    return array_slice($disponibles, 0, $cantidad);
}

/* 8. Manejar aleatorio al añadir al carrito según paquete o qty */
add_filter('woocommerce_add_cart_item_data', 'rifa_handle_random_numbers', 20, 2);
function rifa_handle_random_numbers($cart_item_data, $product_id)
{
    // Solo generar números aleatorios si viene la opción de aleatorio
    if ((isset($_POST['rifa_random']) || isset($_POST['rifa_random_qty'])) && empty($_POST['rifa_numbers'])) {
        $cantidad = isset($_POST['rifa_random_qty']) ? intval($_POST['rifa_random_qty']) : 1;
        $numeros = rifa_generate_random_numbers($product_id, $cantidad);
        $cart_item_data['rifa_numbers'] = implode(', ', $numeros);
    }
    return $cart_item_data;
}

/* ✅ Función AJAX para obtener solo los números disponibles del producto actual */
add_action('wp_ajax_rifa_get_available_numbers', 'rifa_get_available_numbers');
add_action('wp_ajax_nopriv_rifa_get_available_numbers', 'rifa_get_available_numbers');

function rifa_get_available_numbers() {
    global $wpdb;

    $product_id = intval($_POST['product_id']);
    $range = rifa_get_lottery_range($product_id);

    // Todos los números del rango
    $todos = range($range['min'], $range['max']);

    // Buscar los números ya ocupados (para ese producto)
    $resultados = $wpdb->get_col($wpdb->prepare("
        SELECT im.meta_value
        FROM {$wpdb->prefix}woocommerce_order_items oi
        INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta im ON oi.order_item_id = im.order_item_id
        INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta pm ON pm.order_item_id = oi.order_item_id
        WHERE im.meta_key = 'Números de la rifa'
        AND pm.meta_key = '_product_id'
        AND pm.meta_value = %d
    ", $product_id));

    $ocupados = [];
    foreach ($resultados as $r) {
        $nums = array_map('trim', explode(',', $r));
        $ocupados = array_merge($ocupados, $nums);
    }

    // Filtrar los disponibles
    $disponibles = array_diff($todos, $ocupados);

    wp_send_json(['numbers' => array_values($disponibles)]);
}


/* 9. Liberar números si un pedido se cancela */
/* Liberar números si un pedido se cancela */
add_action('woocommerce_order_status_cancelled', 'rifa_release_numbers_on_cancel');
function rifa_release_numbers_on_cancel($order_id)
{
    if (!$order_id)
        return;

    $order = wc_get_order($order_id);
    if (!$order)
        return;

    foreach ($order->get_items() as $item) {
        // Solo eliminamos meta de rifa
        $rifa_numbers = $item->get_meta('Números de la rifa', true);
        if ($rifa_numbers) {
            $item->delete_meta_data('Números de la rifa');
            $item->save();
        }
    }
}
