<?php
    namespace sifen;

    class Medidas {
        static $medidas = array(
            '87' => array(
                'rep' => 'm',
                'desc' =>'Metros'
            ),
            '2366' => array(
                'rep' => 'CPM',
                'desc' =>'Costo por Mil'
            ),
            '2329' => array(
                'rep' => 'UI',
                'desc' =>'Unidad Internacional'
            ),
            '110' => array(
                'rep' => 'M3',
                'desc' =>'Metros cúbicos'
            ),
            '77' => array(
                'rep' => 'UNI',
                'desc' =>'Unidad'
            ),
            '86' => array(
                'rep' => 'g',
                'desc' =>'Gramos'
            ),
            '89' => array(
                'rep' => 'LT',
                'desc' =>'Litros'
            ),
            '90' => array(
                'rep' => 'MG',
                'desc' =>'Miligramos'
            ),
            '91' => array(
                'rep' => 'CM',
                'desc' =>'Centimetros'
            ),
            '92' => array(
                'rep' => 'CM2',
                'desc' =>'Centimetros cuadrados'
            ),
            '93' => array(
                'rep' => 'CM3',
                'desc' =>'Centimetros cubicos'
            ),
            '94' => array(
                'rep' => 'PUL',
                'desc' =>'Pulgadas'
            ),
            '96' => array(
                'rep' => 'MM2',
                'desc' =>'Milímetros cuadrados'
            ),
            '79' => array(
                'rep' => 'kg',
                'desc' =>'  /m² Kilogramos s/ metro cuadrado'
            ),
            '97' => array(
                'rep' => 'AA',
                'desc' =>'Año'
            ),
            '98' => array(
                'rep' => 'ME',
                'desc' =>'Mes'
            ),
            '99' => array(
                'rep' => 'TN',
                'desc' =>'Tonelada'
            ),
            '100' => array(
                'rep' => 'Hs',
                'desc' =>'Hora'
            ),
            '101' => array(
                'rep' => 'Mi',
                'desc' =>'Minuto'
            ),
            '104' => array(
                'rep' => 'DET',
                'desc' =>'Determinación'
            ),
            '103' => array(
                'rep' => 'Ya',
                'desc' =>'Yardas'
            ),
            '108' => array(
                'rep' => 'MT',
                'desc' =>'Metros'
            ),
            '109' => array(
                'rep' => 'M2',
                'desc' =>'Metros cuadrados'
            ),
            '95' => array(
                'rep' => 'MM',
                'desc' =>'Milímetros'
            ),
            '666' => array(
                'rep' => 'Se',
                'desc' =>'Segundo'
            ),
            '102' => array(
                'rep' => 'Di',
                'desc' =>'Día'
            ),
            '83' => array(
                'rep' => 'kg',
                'desc' =>'Kilogramos'
            ),
            '88' => array(
                'rep' => 'ML',
                'desc' =>'Mililitros'
            ),
            '625' => array(
                'rep' => 'Km',
                'desc' =>'Kilómetros'
            ),
            '660' => array(
                'rep' => 'ml',
                'desc' =>'Metro lineal'
            ),
            '885' => array(
                'rep' => 'GL',
                'desc' =>'Unidad Medida Global'
            ),
            '891' => array(
                'rep' => 'pm',
                'desc' =>'Por Milaje'
            ),
            '869' => array(
                'rep' => 'ha',
                'desc' =>'Hectáreas'
            ),
            '569' => array(
                'rep' => 'ración',
                'desc' =>'Ración'
            )
        );        

        public static function medida($medida) {
            return self::$medidas[ $medida ];
        }
    }

?>