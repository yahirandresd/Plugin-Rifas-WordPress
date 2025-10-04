<?php
add_action('wp_footer', function() {
    ?>
    <script>
    jQuery(document).ready(function($){
        var selectPaquetes = $('#paquetes');
        var inputCantidad = $('input.qty');

        if (!selectPaquetes.length || !inputCantidad.length) return;

        function enforceQty() {
            var selected = parseInt(selectPaquetes.val());

            if (selected === 1) {
                inputCantidad.prop('disabled', false).show();
            } else if (!isNaN(selected)) {
                inputCantidad.val(1).prop('disabled', true).hide();
            }
        }

        // Inicializamos
        enforceQty();

        // Cambios en el select
        selectPaquetes.on('change', enforceQty);

        // Evitar que se modifique manualmente
        inputCantidad.on('input', function(){
            if (parseInt(selectPaquetes.val()) !== 1) {
                $(this).val(1);
            }
        });

        // Detectar actualizaciones de WooCommerce AJAX
        $(document.body).on('updated_wc_div', enforceQty);
    });
    </script>
    <?php
});
