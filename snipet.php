<?php
add_action('wp_footer', function () {
    ?>
    <script>
        jQuery(document).ready(function ($) {
            var selectPaquetes = $('#paquetes');
            var inputCantidad = $('input.qty');

            if (!selectPaquetes.length || !inputCantidad.length) return;

            function enforceQty() {
                var selected = parseInt(selectPaquetes.val());

                if (selected === 1) {
                    inputCantidad.prop('disabled', false).show();
                    // qty editable, se usa el valor del input
                    var randomQty = parseInt(inputCantidad.val()) || 1;
                } else if (!isNaN(selected)) {
                    inputCantidad.val(1).prop('disabled', true).hide();
                    // qty fijo = 1, usamos el valor del paquete
                    var randomQty = selected;
                }

                // Guardar cantidad para números aleatorios
                if ($('#rifa_random_qty').length === 0) {
                    $('<input type="hidden" id="rifa_random_qty" name="rifa_random_qty">').appendTo('form.cart');
                }
                $('#rifa_random_qty').val(randomQty);
            }

            // Inicializar
            enforceQty();

            // Cambios en el select
            selectPaquetes.on('change', enforceQty);

            // Evitar que se modifique manualmente cuando no es paquete 1
            inputCantidad.on('input', function () {
                if (parseInt(selectPaquetes.val()) === 1) {
                    $('#rifa_random_qty').val(parseInt($(this).val()) || 1);
                } else {
                    $(this).val(1);
                }
            });

            // Detectar actualizaciones de WooCommerce AJAX
            $(document.body).on('updated_wc_div', enforceQty);
        });
    </script>
    <?php
});

