<?php
    namespace sifen;

    class Remisiones {
        static $remisiones = array(
            1 => 'Traslado por ventas',
            2 => 'Traslado por consignación',
            3 => 'Exportación',
            4 => 'Traslado por compra',
            5 => 'Importación',
            6 => 'Traslado por devolución',
            7 => 'Traslado entre locales de la empresa ',
            8 => 'Traslado de bienes por transformación',
            9 => 'Traslado de bienes por reparación',
            10 => 'Traslado por emisor móvil',
            11 => 'Exhibición o demostración',
            12 => 'Participación en ferias',
            13 => 'Traslado de encomienda',
            14 => 'Decomiso'
        );        

        public static function remisiones($remision) {
            return self::$remisiones[ $remision ];
        }
    }

?>