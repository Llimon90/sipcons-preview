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

    echo "\n=== PDFs en la biblioteca de medios (fichas técnicas / manuales) ===\n";
    $stmt = $pdo->query("
        SELECT COUNT(*) AS n
        FROM {$posts}
        WHERE post_type = 'attachment' AND post_mime_type = 'application/pdf'
    ");
    echo '  Total de PDFs subidos a WordPress: ' . $stmt->fetch()['n'] . "\n";

    echo "\n  -- PDFs cuyo post_parent SÍ es un producto publicado (asociación directa) --\n";
    $stmt = $pdo->query("
        SELECT a.ID AS pdf_id, a.post_title AS pdf_titulo, a.post_parent AS producto_id, p.post_title AS producto_titulo,
               am.meta_value AS ruta
        FROM {$posts} a
        JOIN {$posts} p ON p.ID = a.post_parent AND p.post_type = 'product' AND p.post_status = 'publish'
        LEFT JOIN {$postmeta} am ON am.post_id = a.ID AND am.meta_key = '_wp_attached_file'
        WHERE a.post_type = 'attachment' AND a.post_mime_type = 'application/pdf'
        ORDER BY p.post_title
        LIMIT 30
    ");
    $conParent = 0;
    foreach ($stmt as $row) {
        $conParent++;
        echo "     producto #{$row['producto_id']} {$row['producto_titulo']}  ->  {$row['pdf_titulo']} ({$row['ruta']})\n";
    }
    if (!$conParent) echo "     (ninguno — los PDFs no están asociados por post_parent a un producto)\n";

    echo "\n  -- Primeros 15 PDFs sin filtrar (para ver dónde viven y cómo se llaman) --\n";
    $stmt = $pdo->query("
        SELECT a.ID, a.post_title, a.post_parent, am.meta_value AS ruta
        FROM {$posts} a
        LEFT JOIN {$postmeta} am ON am.post_id = a.ID AND am.meta_key = '_wp_attached_file'
        WHERE a.post_type = 'attachment' AND a.post_mime_type = 'application/pdf'
        ORDER BY a.ID DESC
        LIMIT 15
    ");
    foreach ($stmt as $row) {
        echo "     #{$row['ID']} \"{$row['post_title']}\" post_parent={$row['post_parent']}  ruta: {$row['ruta']}\n";
    }

    echo "\n  -- Meta keys de productos que parecen apuntar a un archivo/manual/ficha --\n";
    $stmt = $pdo->query("
        SELECT DISTINCT meta_key
        FROM {$postmeta}
        WHERE meta_key LIKE '%pdf%' OR meta_key LIKE '%manual%' OR meta_key LIKE '%ficha%'
           OR meta_key LIKE '%download%' OR meta_key LIKE '%adjunto%' OR meta_key LIKE '%archivo%'
           OR meta_key LIKE '%datasheet%' OR meta_key LIKE '%catalogo%'
        ORDER BY meta_key
    ");
    $metaKeys = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!$metaKeys) {
        echo "     (ninguno)\n";
    }
    foreach ($metaKeys as $mk) {
        $stmt2 = $pdo->prepare("SELECT COUNT(*) AS n FROM {$postmeta} WHERE meta_key = :mk AND meta_value <> ''");
        $stmt2->execute(['mk' => $mk]);
        $n = $stmt2->fetch()['n'];
        echo "     {$mk}  ({$n} productos con valor)\n";
        if ($n > 0) {
            $stmt3 = $pdo->prepare("SELECT post_id, LEFT(meta_value,150) AS v FROM {$postmeta} WHERE meta_key = :mk AND meta_value <> '' LIMIT 2");
            $stmt3->execute(['mk' => $mk]);
            foreach ($stmt3 as $ej) {
                echo "        ej. post_id={$ej['post_id']}: {$ej['v']}\n";
            }
        }
    }

    echo "\n=== Productos actuales en 'De Plataforma' (para saber cuál pide quitar el dueño) ===\n";
    $stmt = $pdo->query("
        SELECT p.ID, p.post_title, p.post_status
        FROM {$posts} p
        JOIN {$termRel} tr ON tr.object_id = p.ID
        JOIN {$termTax} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
        JOIN {$terms} t ON t.term_id = tt.term_id
        WHERE tt.taxonomy = 'product_cat' AND t.slug = 'de-plataforma'
        ORDER BY p.post_title
    ");
    foreach ($stmt as $row) {
        echo "  #{$row['ID']} \"{$row['post_title']}\" [{$row['post_status']}]\n";
    }

    echo "\n=== Modelos pedidos por el dueño: ¿ya existen (aunque sea publicados/no publicados o mal categorizados)? ===\n";
    $modelosPedidos = [
        'BAR-8', 'BAPRE-1', 'BAPRE-3', 'BAPRE-600',
        'BAPCA-80', 'BAPCA-100', 'BAPCA-200', 'BAPCA-600', 'BAPCA-800',
        'BP-80', 'BP-100', 'BP-200', 'BP-500',
        'BAVET-200', 'BAPIC-100', 'BAPIC-300',
        'PLABA-0', 'PLABA-12', 'PLABA-15', 'BAPER-12',
        'BAPO-15', 'BACO-30', 'BcomS',
    ];
    foreach ($modelosPedidos as $modelo) {
        $stmt = $pdo->prepare("
            SELECT p.ID, p.post_title, p.post_status,
                   GROUP_CONCAT(DISTINCT t.name ORDER BY t.name SEPARATOR ', ') AS categorias
            FROM {$posts} p
            LEFT JOIN {$termRel} tr ON tr.object_id = p.ID
            LEFT JOIN {$termTax} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat'
            LEFT JOIN {$terms} t ON t.term_id = tt.term_id
            WHERE p.post_type = 'product' AND p.post_title LIKE :buscar
            GROUP BY p.ID
        ");
        $stmt->execute(['buscar' => '%' . $modelo . '%']);
        $encontrados = $stmt->fetchAll();
        if (!$encontrados) {
            echo "  {$modelo}: NO EXISTE en la base de datos\n";
        } else {
            foreach ($encontrados as $row) {
                echo "  {$modelo}: existe -> #{$row['ID']} \"{$row['post_title']}\" [{$row['post_status']}] categorías: " . ($row['categorias'] ?: '(ninguna)') . "\n";
            }
        }
    }

    echo "\nOK — copia y pega toda esta salida.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
}
