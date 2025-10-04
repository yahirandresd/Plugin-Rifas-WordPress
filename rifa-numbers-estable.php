<?php
/*
Plugin Name: Números de Rifa WooCommerce
Description: Permite al cliente elegir números de rifa en la página de producto, los guarda en el pedido y valida que no se repitan.
Version: 1.1
Author: Yahir Andres Rangel Dueñas - NetVuk Interactive 
*/

/* 1. Mostrar campo en la página de producto */
add_action( 'woocommerce_before_add_to_cart_quantity', 'rifa_custom_number_field' );
function rifa_custom_number_field() {
    ?>
    <div class="rifa-numbers" style="margin:15px 0;padding:10px;border:1px solid #ddd;">
        <label for="rifa_numbers"><strong>Elige tus números (separados por comas):</strong></label>
        <input type="text" name="rifa_numbers" id="rifa_numbers" placeholder="Ej: 5, 23, 77" style="width:100%;margin-top:5px;">
        <p style="margin:8px 0;">
            <label><input type="checkbox" name="rifa_random" value="1"> Asignar números aleatorios</label>
        </p>
    </div>
    <?php
}

/* 2. Validar que los números no estén repetidos */
add_filter( 'woocommerce_add_to_cart_validation', 'rifa_validate_numbers', 10, 2 );
function rifa_validate_numbers( $passed, $product_id ) {
    if ( isset($_POST['rifa_numbers']) && ! empty($_POST['rifa_numbers']) ) {
        global $wpdb;

        // Números ingresados por el usuario
        $numbers = array_map( 'trim', explode( ',', sanitize_text_field($_POST['rifa_numbers'])) );

        foreach ( $numbers as $num ) {
            // Buscar si el número ya existe en pedidos
            $exists = $wpdb->get_var( $wpdb->prepare("
                SELECT COUNT(meta_id) 
                FROM {$wpdb->prefix}woocommerce_order_itemmeta 
                WHERE meta_key = 'Números de la rifa' 
                AND meta_value LIKE %s
            ", '%' . $num . '%' ));

            if ( $exists > 0 ) {
                wc_add_notice( "El número <strong>{$num}</strong> ya fue comprado. Por favor elige otro.", 'error' );
                return false; // ❌ No deja añadir al carrito
            }
        }
    }
    return $passed;
}

/* 3. Guardar los números en el carrito */
add_filter( 'woocommerce_add_cart_item_data', 'rifa_add_cart_item_data', 10, 2 );
function rifa_add_cart_item_data( $cart_item_data, $product_id ) {
    if ( isset($_POST['rifa_numbers']) && ! empty($_POST['rifa_numbers']) ) {
        $cart_item_data['rifa_numbers'] = sanitize_text_field($_POST['rifa_numbers']);
    }
    if ( isset($_POST['rifa_random']) ) {
        $cart_item_data['rifa_random'] = true;
    }
    return $cart_item_data;
}

/* 4. Mostrar en carrito y checkout */
add_filter( 'woocommerce_get_item_data', 'rifa_display_cart_item_data', 10, 2 );
function rifa_display_cart_item_data( $item_data, $cart_item ) {
    if ( isset($cart_item['rifa_numbers']) ) {
        $item_data[] = array(
            'name'  => 'Números de la rifa',
            'value' => $cart_item['rifa_numbers'],
        );
    }
    if ( isset($cart_item['rifa_random']) ) {
        $item_data[] = array(
            'name'  => 'Números aleatorios',
            'value' => 'Sí',
        );
    }
    return $item_data;
}

/* 5. Guardar en los detalles del pedido */
add_action( 'woocommerce_checkout_create_order_line_item', 'rifa_add_order_item_meta', 10, 4 );
function rifa_add_order_item_meta( $item, $cart_item_key, $values, $order ) {
    if ( isset($values['rifa_numbers']) ) {
        $item->add_meta_data( 'Números de la rifa', $values['rifa_numbers'], true );
    }
    if ( isset($values['rifa_random']) ) {
        $item->add_meta_data( 'Números aleatorios', 'Sí', true );
    }
}
