<?php
/**
 * Sitemap de sipcons.com. Se sirve como /sitemap.xml (ver .htaccess: reescritura
 * interna, no redirección — la URL que ve Google no cambia).
 *
 * Además de las páginas fijas del sitio, agrega una entrada por cada producto
 * publicado en el catálogo (producto.php?slug=...), leído en vivo de
 * WooCommerce. Antes esas ~100 fichas de producto no estaban en el sitemap y
 * dependían de que Google las descubriera solo seleccionando enlaces desde
 * productos.php. Si la base de datos no responde, el sitemap sigue saliendo
 * igual con las páginas fijas (nunca se cae por completo).
 */

declare(strict_types=1);
error_reporting(0);

header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: public, max-age=3600');

const SIPCONS_BASE = 'https://sipcons.com';

$paginasFijas = [
    ['/', '2026-09-08'],
    ['/quienes-somos.html', '2026-09-08'],
    ['/servicios.html', '2026-09-08'],
    ['/productos.php', '2026-09-08'],
    ['/soporte.html', '2026-09-08'],
    ['/contacto.html', '2026-09-08'],
    ['/calibracion.html', '2026-09-08'],
    ['/casos-de-exito.html', '2026-09-08'],
    ['/recursos.html', '2026-09-08'],
    ['/recurso-calibracion-nom.html', '2026-09-08'],
    ['/recurso-como-elegir-bascula.html', '2026-09-08'],
    ['/industrias-abarrotes.html', '2026-09-08'],
    ['/industrias-ferreterias.html', '2026-09-08'],
    ['/industrias-industria.html', '2026-09-08'],
    ['/industrias-restaurantes.html', '2026-09-08'],
    ['/cobertura-mexicali.html', '2026-09-08'],
    ['/cobertura-ensenada.html', '2026-09-08'],
    ['/cobertura-tecate.html', '2026-09-08'],
];

$slugsProductos = [];
try {
    require_once __DIR__ . '/inc/productos-data.php';
    foreach (sipcons_obtener_productos()['productos'] as $p) {
        if ($p['slug'] !== '') $slugsProductos[] = $p['slug'];
    }
} catch (Throwable $e) {
    // Sin catálogo disponible: el sitemap sale solo con las páginas fijas.
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

foreach ($paginasFijas as [$ruta, $fecha]) {
    echo '  <url><loc>' . SIPCONS_BASE . $ruta . '</loc><lastmod>' . $fecha . '</lastmod></url>' . "\n";
}
foreach ($slugsProductos as $slug) {
    $url = SIPCONS_BASE . '/producto.php?slug=' . rawurlencode($slug);
    echo '  <url><loc>' . htmlspecialchars($url, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</loc></url>' . "\n";
}

echo '</urlset>' . "\n";
