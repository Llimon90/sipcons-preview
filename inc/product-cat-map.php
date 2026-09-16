<?php
/**
 * SIPCONS · clasificación manual de las categorías de WooCommerce.
 *
 * WordPress guarda "tipo de equipo" y "marca" mezclados en una sola taxonomía
 * (product_cat) sin distinguirlos — un producto puede traer varias categorías
 * a la vez (ej. "Básculas" + "De Precisión" + "Rhino" + "Sipcons"). Este archivo
 * decide, por slug de categoría, si cuenta como tipo o como marca para armar
 * los dos filtros del catálogo (igual que el diseño original).
 *
 * Si algún día se crea una categoría nueva en WordPress que no esté aquí, el
 * producto sigue apareciendo en "Todos los productos" (nunca desaparece),
 * pero no tendrá chip de filtro propio hasta que se agregue su slug aquí.
 *
 * Orden en TIPOS: de más específico a más genérico. Un producto puede tener
 * varias categorías de "tipo" a la vez (ej. Básculas + De Precisión); se usa
 * la primera que haga match en este orden como su tipo principal.
 */

return [
    'tipos' => [
        'de-precision'     => 'Balanzas de precisión',
        'de-plataforma'    => 'Plataformas',
        'contadoras'       => 'Contadoras',
        'etiquetadoras'    => 'Etiquetadoras',
        'porcionadoras'    => 'Porcionadoras',
        'colgantes'        => 'Básculas colgantes',
        'a-prueba-de-agua' => 'A prueba de agua',
        'comerciales'      => 'Básculas comerciales',
        'puntos-de-venta'  => 'Puntos de venta',
        'punto-de-venta'   => 'Puntos de venta', // duplicado de captura en WP, mismo filtro que puntos-de-venta
        'touch'            => 'Equipos touch',
        'impresoras'       => 'Impresoras',
        'scanners'         => 'Scanners',
        'indicadores'      => 'Indicadores de peso',
        'consumibles'      => 'Consumibles',
        'basculas'         => 'Básculas', // genérico: solo se usa si no hay un tipo más específico
    ],

    'marcas' => [
        'cas'        => 'CAS',
        'rhino'      => 'Rhino',
        'sam4s'      => 'SAM4S',
        'bixolon'    => 'Bixolon',
        'honeywell'  => 'Honeywell',
        'ncr'        => 'NCR',
        'copesa'     => 'Copesa',
        'epelsa'     => 'Epelsa',
        'megellan'   => 'Magellan',
        'youjie'     => 'Youjie',
        'inovacion'  => 'Innovación',
        'mettler'    => 'Mettler-Toledo',
    ],

    // Categorías que no aportan nada como filtro (etiqueta genérica o vacías).
    'ignorar' => [
        'sipcons',
        'basculas-pos',
        'cajones-de-dinero',
    ],
];
