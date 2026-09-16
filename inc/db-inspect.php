<?php
/**
 * SIPCONS · diagnóstico temporal de la base de datos de WordPress/WooCommerce.
 *
 * Muestra qué taxonomías, categorías, marcas y metadatos de producto existen
 * realmente en sipcons1_basedatos, para poder mapear los filtros del catálogo
 * a los datos reales. Es SOLO LECTURA (no modifica nada).
 *
 * BORRA ESTE ARCHIVO DEL SERVIDOR EN CUANTO TERMINES DE USARLO.
 * Requiere ?token=... igual al 'inspect_token' de inc/db-config.php.
 */

declare(strict_types=1);
require __DIR__ . '/db.php';

$config = require __DIR__ . '/db-config.php';

if (!hash_equals((string)($config['inspect_token'] ?? ''), (string)($_GET['token'] ?? ''))) {
    http_response_code(403);
    exit('Acceso denegado. Agrega ?token=... (ver inc/db-config.php).');
}

header('Content-Type: text/plain; charset=UTF-8');

try {
    $pdo = sipcons_db();
    $posts   = sipcons_tabla('posts');
    $postmeta = sipcons_tabla('postmeta');
    $terms   = sipcons_tabla('terms');
    $termTax = sipcons_tabla('term_taxonomy');
    $termRel = sipcons_tabla('term_relationships');

    echo "=== Taxonomías presentes (tabla {$termTax}) ===\n";
    $stmt = $pdo->query("SELECT taxonomy, COUNT(*) AS n FROM {$termTax} GROUP BY taxonomy ORDER BY taxonomy");
    foreach ($stmt as $row) {
        echo "  {$row['taxonomy']}  ({$row['n']} términos)\n";
    }

    echo "\n=== Productos publicados ===\n";
    $stmt = $pdo->query("SELECT COUNT(*) AS n FROM {$posts} WHERE post_type='product' AND post_status='publish'");
    echo '  Total: ' . $stmt->fetch()['n'] . "\n";

    echo "\n=== Términos de 'product_cat' (categorías) ===\n";
    $stmt = $pdo->query("
        SELECT t.name, t.slug, tt.count
        FROM {$terms} t
        JOIN {$termTax} tt ON tt.term_id = t.term_id
        WHERE tt.taxonomy = 'product_cat'
        ORDER BY t.name
    ");
    foreach ($stmt as $row) {
        echo "  {$row['name']}  (slug: {$row['slug']}, {$row['count']} productos)\n";
    }

    echo "\n=== Términos de taxonomías que parecen ser 'marca' (pa_*, *brand*) ===\n";
    $stmt = $pdo->query("SELECT DISTINCT taxonomy FROM {$termTax} WHERE taxonomy LIKE 'pa\\_%' OR taxonomy LIKE '%brand%'");
    $marcaTax = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!$marcaTax) {
        echo "  (no se encontró ninguna taxonomía de marca por nombre; revisa la lista de taxonomías de arriba)\n";
    }
    foreach ($marcaTax as $tax) {
        echo "  -- taxonomía: {$tax} --\n";
        $stmt2 = $pdo->prepare("
            SELECT t.name, t.slug, tt.count
            FROM {$terms} t
            JOIN {$termTax} tt ON tt.term_id = t.term_id
            WHERE tt.taxonomy = :tax
            ORDER BY t.name
        ");
        $stmt2->execute(['tax' => $tax]);
        foreach ($stmt2 as $row) {
            echo "     {$row['name']}  (slug: {$row['slug']}, {$row['count']} productos)\n";
        }
    }

    echo "\n=== Meta keys del producto publicado más reciente (para confirmar precio/stock/imagen) ===\n";
    $stmt = $pdo->query("SELECT ID, post_title FROM {$posts} WHERE post_type='product' AND post_status='publish' ORDER BY ID DESC LIMIT 1");
    $ultimo = $stmt->fetch();
    if ($ultimo) {
        echo "  Producto: #{$ultimo['ID']} — {$ultimo['post_title']}\n";
        $stmt2 = $pdo->prepare("SELECT meta_key, LEFT(meta_value, 80) AS sample FROM {$postmeta} WHERE post_id = :id ORDER BY meta_key");
        $stmt2->execute(['id' => $ultimo['ID']]);
        foreach ($stmt2 as $row) {
            echo "     {$row['meta_key']} = {$row['sample']}\n";
        }
    } else {
        echo "  (no hay productos publicados)\n";
    }

    echo "\n=== Últimos 10 productos con sus términos (categoría/marca/tags) ===\n";
    $stmt = $pdo->query("
        SELECT p.ID, p.post_title,
               GROUP_CONCAT(DISTINCT CONCAT(tt.taxonomy, ':', t.name) ORDER BY tt.taxonomy SEPARATOR ' | ') AS terminos
        FROM {$posts} p
        LEFT JOIN {$termRel} tr ON tr.object_id = p.ID
        LEFT JOIN {$termTax} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
        LEFT JOIN {$terms} t ON t.term_id = tt.term_id
        WHERE p.post_type = 'product' AND p.post_status = 'publish'
        GROUP BY p.ID
        ORDER BY p.ID DESC
        LIMIT 10
    ");
    foreach ($stmt as $row) {
        echo "  #{$row['ID']} {$row['post_title']}\n";
        echo "     " . ($row['terminos'] ?: '(sin términos)') . "\n";
    }

    echo "\nOK — copia y pega toda esta salida.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
}
