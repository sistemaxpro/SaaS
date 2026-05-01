<?php
    namespace sifen;

    class Devoluciones {
        static $devoluciones = array(
            1 => "Devolución y Ajuste de precios",
            2 => "Devolución",
            3 => "Descuento",
            4 => "Bonificación",
            5 => "Crédito incobrable",
            6 => "Recupero de costo",
            7 => "Recupero de gasto",
            8 => "Ajuste de precio"
        );        

        public static function devoluciones($devolucion) {
            return self::$devoluciones[ $devolucion ];
        }
    }

?>