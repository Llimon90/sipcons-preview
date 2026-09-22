<?php
/**
 * SIPCONS · lee el catálogo real de productos desde WooCommerce (solo lectura)
 * y arma los datos que necesita productos.html: tarjetas + chips de filtro.
 */

declare(strict_types=1);
require_once __DIR__ . '/db.php';

const SIPCONS_UPLOADS_BASE = 'https://sipcons.com/tienda/wp-content/uploads/';

function sipcons_obtener_productos(): array {
    $mapa = require __DIR__ . '/product-cat-map.php';
    $pdo  = sipcons_db();

    $posts    = sipcons_tabla('posts');
    $postmeta = sipcons_tabla('postmeta');
    $terms    = sipcons_tabla('terms');
    $termTax  = sipcons_tabla('term_taxonomy');
    $termRel  = sipcons_tabla('term_relationships');

    // --- 1) Productos publicados -------------------------------------
    $productosRaw = $pdo->query("
        SELECT ID, post_title, post_name, post_excerpt
        FROM {$posts}
        WHERE post_type = 'product' AND post_status = 'publish'
        ORDER BY post_title ASC
    ")->fetchAll();

    if (!$productosRaw) {
        return ['productos' => [], 'tipos' => [], 'marcas' => []];
    }

    $ids = array_column($productosRaw, 'ID');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    // --- 2) Categorías (product_cat) de cada producto -----------------
    $stmt = $pdo->prepare("
        SELECT tr.object_id AS post_id, t.slug
        FROM {$termRel} tr
        JOIN {$termTax} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
        JOIN {$terms} t ON t.term_id = tt.term_id
        WHERE tt.taxonomy = 'product_cat' AND tr.object_id IN ({$placeholders})
    ");
    $stmt->execute($ids);
    $categoriasPorProducto = [];
    foreach ($stmt as $row) {
        $categoriasPorProducto[(int)$row['post_id']][] = $row['slug'];
    }

    // --- 3) Imagen destacada de cada producto -------------------------
    $stmt = $pdo->prepare("
        SELECT post_id, meta_value AS attachment_id
        FROM {$postmeta}
        WHERE meta_key = '_thumbnail_id' AND post_id IN ({$placeholders})
    ");
    $stmt->execute($ids);
    $thumbIdPorProducto = [];
    $attachmentIds = [];
    foreach ($stmt as $row) {
        $thumbIdPorProducto[(int)$row['post_id']] = (int)$row['attachment_id'];
        $attachmentIds[] = (int)$row['attachment_id'];
    }

    $rutaPorAttachment = [];
    $metaPorAttachment = [];
    if ($attachmentIds) {
        $attachmentIds = array_values(array_unique($attachmentIds));
        $ph2 = implode(',', array_fill(0, count($attachmentIds), '?'));
        $stmt = $pdo->prepare("
            SELECT post_id, meta_value AS ruta
            FROM {$postmeta}
            WHERE meta_key = '_wp_attached_file' AND post_id IN ({$ph2})
        ");
        $stmt->execute($attachmentIds);
        foreach ($stmt as $row) {
            $rutaPorAttachment[(int)$row['post_id']] = $row['ruta'];
        }

        // Metadatos con los tamaños que WordPress ya generó (thumbnail, medium, etc.)
        // para no servir siempre la foto original de varios cientos de KB.
        $stmt = $pdo->prepare("
            SELECT post_id, meta_value AS metadata
            FROM {$postmeta}
            WHERE meta_key = '_wp_attachment_metadata' AND post_id IN ({$ph2})
        ");
        $stmt->execute($attachmentIds);
        foreach ($stmt as $row) {
            $datos = @unserialize($row['metadata'], ['allowed_classes' => false]);
            if (is_array($datos)) {
                $metaPorAttachment[(int)$row['post_id']] = $datos;
            }
        }
    }

    // Elige una miniatura ya generada por WordPress (mucho más liviana que el
    // original) para el grid del catálogo; conserva el original para la
    // página de detalle de cada producto.
    $sipconsImagenChica = static function (?int $attId) use ($rutaPorAttachment, $metaPorAttachment): ?string {
        if (!$attId || !isset($rutaPorAttachment[$attId])) return null;
        $rutaOriginal = $rutaPorAttachment[$attId];
        $meta = $metaPorAttachment[$attId] ?? null;
        $sizes = $meta['sizes'] ?? null;
        foreach (['woocommerce_thumbnail', 'medium', 'shop_catalog', 'thumbnail'] as $preferida) {
            if (isset($sizes[$preferida]['file'])) {
                $dir = dirname($rutaOriginal);
                $dir = $dir === '.' ? '' : $dir . '/';
                return SIPCONS_UPLOADS_BASE . $dir . $sizes[$preferida]['file'];
            }
        }
        return SIPCONS_UPLOADS_BASE . $rutaOriginal; // sin tamaños generados: usar el original
    };

    // --- 4) Clasificar tipo/marca y armar cada tarjeta ----------------
    $tiposSlugs  = array_keys($mapa['tipos']);
    $marcasSlugs = array_keys($mapa['marcas']);

    $productos = [];
    $conteoTipos  = array_fill_keys($tiposSlugs, 0);
    $conteoMarcas = array_fill_keys($marcasSlugs, 0);

    foreach ($productosRaw as $p) {
        $id = (int)$p['ID'];
        $cats = $categoriasPorProducto[$id] ?? [];
        $cats = array_map(static fn($s) => $s === 'punto-de-venta' ? 'puntos-de-venta' : $s, $cats);

        $tipoSlug = null;
        foreach ($tiposSlugs as $slug) {
            if ($slug === 'punto-de-venta') continue; // ya normalizado arriba
            if (in_array($slug, $cats, true)) { $tipoSlug = $slug; break; }
        }

        $marcaSlug = null;
        foreach ($marcasSlugs as $slug) {
            if (in_array($slug, $cats, true)) { $marcaSlug = $slug; break; }
        }

        if ($tipoSlug !== null) $conteoTipos[$tipoSlug]++;
        if ($marcaSlug !== null) $conteoMarcas[$marcaSlug]++;

        $attId = $thumbIdPorProducto[$id] ?? null;
        $imagen = $sipconsImagenChica($attId);
        $imagenGrande = ($attId && isset($rutaPorAttachment[$attId]))
            ? SIPCONS_UPLOADS_BASE . $rutaPorAttachment[$attId]
            : null;

        $productos[] = [
            'id'            => $id,
            'titulo'        => $p['post_title'],
            'slug'          => $p['post_name'],
            'descripcion'   => trim(preg_replace('/\s+/', ' ', strip_tags((string)$p['post_excerpt']))),
            'imagen'        => $imagen,
            'imagen_grande' => $imagenGrande,
            'imagen_id'     => $attId,
            'tipo_slug'   => $tipoSlug,
            'tipo_label'  => $tipoSlug ? $mapa['tipos'][$tipoSlug] : 'Otros equipos',
            'marca_slug'  => $marcaSlug,
            'marca_label' => $marcaSlug ? $mapa['marcas'][$marcaSlug] : '',
            'cats'        => $cats,
        ];
    }

    // --- Orden de aparición pedido por el dueño --------------------------
    // Regla general dentro de cada tipo de equipo (filtros por categoría):
    // Mettler-Toledo, luego Rhino, luego CAS, luego el resto — salvo Puntos
    // de venta y Consumibles, que tienen su propia regla (ver
    // sipcons_subrango_producto). Los tipos se agrupan en este orden para
    // que, al filtrar por MARCA, el equipo aparezca primero y los
    // consumibles/refacciones siempre queden al final.
    $ordenTipos = array_flip([
        'de-precision', 'de-plataforma', 'contadoras', 'etiquetadoras',
        'porcionadoras', 'colgantes', 'a-prueba-de-agua', 'comerciales',
        'puntos-de-venta', 'touch', 'impresoras', 'scanners', 'indicadores',
        'basculas', 'consumibles',
    ]);
    usort($productos, static function (array $a, array $b) use ($ordenTipos): int {
        $ta = $ordenTipos[$a['tipo_slug'] ?? ''] ?? 999;
        $tb = $ordenTipos[$b['tipo_slug'] ?? ''] ?? 999;
        if ($ta !== $tb) return $ta <=> $tb;
        $sa = sipcons_subrango_producto($a);
        $sb = sipcons_subrango_producto($b);
        if ($sa !== $sb) return $sa <=> $sb;
        return strcasecmp($a['titulo'], $b['titulo']);
    });

    // --- 5) Chips de filtro (solo los que sí tienen productos) --------
    $chipsTipos = [];
    foreach ($mapa['tipos'] as $slug => $label) {
        if ($slug === 'punto-de-venta') continue; // duplicado ya fusionado
        if ($conteoTipos[$slug] > 0) {
            $chipsTipos[] = ['slug' => $slug, 'label' => $label, 'count' => $conteoTipos[$slug]];
        }
    }

    $chipsMarcas = [];
    foreach ($mapa['marcas'] as $slug => $label) {
        if ($conteoMarcas[$slug] > 0) {
            $chipsMarcas[] = ['slug' => $slug, 'label' => $label, 'count' => $conteoMarcas[$slug]];
        }
    }

    return [
        'productos' => $productos,
        'tipos'     => $chipsTipos,
        'marcas'    => $chipsMarcas,
    ];
}

/**
 * Orden dentro de cada tipo de equipo, según lo pedido por el dueño:
 * - Puntos de venta: primero básculas que integran POS, luego terminales
 *   POS, luego periféricos (impresoras, scanners, cajones de dinero).
 * - Consumibles: primero etiquetas, luego cabezas térmicas, luego teclados.
 * - Todo lo demás (incluida Plataformas): Mettler-Toledo, luego Rhino,
 *   luego CAS, luego el resto — es el orden de marca que pide el dueño
 *   para los filtros por característica de equipo.
 */
function sipcons_subrango_producto(array $p): int {
    $tipo   = $p['tipo_slug'] ?? '';
    $marca  = $p['marca_slug'] ?? '';
    $titulo = mb_strtoupper($p['titulo']);
    $cats   = $p['cats'] ?? [];

    if ($tipo === 'puntos-de-venta') {
        if (in_array('basculas', $cats, true) || in_array('comerciales', $cats, true)) return 0;
        if (strpos($titulo, 'TERMINAL') !== false || in_array('touch', $cats, true)) return 1;
        if (in_array('impresoras', $cats, true) || in_array('scanners', $cats, true) || in_array('cajones-de-dinero', $cats, true)) return 2;
        return 3;
    }

    if ($tipo === 'consumibles') {
        if (strpos($titulo, 'ETIQUETA') !== false || strpos($titulo, 'ROLLO') !== false) return 0;
        if (strpos($titulo, 'CABEZA') !== false) return 1;
        if (strpos($titulo, 'TECLADO') !== false) return 2;
        return 3;
    }

    switch ($marca) {
        case 'mettler': return 0;
        case 'rhino':   return 1;
        case 'cas':     return 2;
        default:        return 3;
    }
}

/**
 * Selección aleatoria de productos para el carrusel "Equipo listo para
 * trabajar desde hoy" del inicio. Mezcla marcas y tipos de equipo (Toledo,
 * Rhino, touch, impresoras, plataformas, etc.) en vez de repetir siempre
 * los mismos; se recalcula en cada carga de la página. Solo entran
 * productos con foto y no se incluyen consumibles/refacciones.
 */
function sipcons_productos_destacados_aleatorios(array $productos, int $cantidad = 8): array {
    $pool = array_values(array_filter($productos, static function (array $p): bool {
        return !empty($p['imagen']) && ($p['tipo_slug'] ?? '') !== 'consumibles';
    }));
    if (!$pool) return [];

    $porTipo = [];
    foreach ($pool as $p) {
        $porTipo[$p['tipo_slug'] ?? '_sin_tipo'][] = $p;
    }
    foreach ($porTipo as &$grupo) {
        shuffle($grupo);
    }
    unset($grupo);

    $tipos = array_keys($porTipo);
    shuffle($tipos);

    $seleccion = [];
    $usados = [];

    // Primera pasada: un producto de cada tipo distinto (variedad garantizada).
    foreach ($tipos as $tipo) {
        if (count($seleccion) >= $cantidad) break;
        $candidato = array_shift($porTipo[$tipo]);
        if ($candidato) {
            $seleccion[] = $candidato;
            $usados[$candidato['id']] = true;
        }
    }

    // Si faltan lugares, se completa con lo que quede, sin repetir.
    if (count($seleccion) < $cantidad) {
        $resto = [];
        foreach ($porTipo as $grupo) {
            foreach ($grupo as $p) {
                if (!isset($usados[$p['id']])) $resto[] = $p;
            }
        }
        shuffle($resto);
        foreach ($resto as $p) {
            if (count($seleccion) >= $cantidad) break;
            $seleccion[] = $p;
        }
    }

    shuffle($seleccion);
    return $seleccion;
}

/**
 * Todas las fotos de un producto (destacada + galería de WooCommerce), para
 * la página de detalle. Cada elemento trae 'chica' (miniatura, para el
 * carrusel de thumbnails) y 'grande' (original, para el zoom/modal).
 */
function sipcons_obtener_galeria_producto(int $productId, ?int $thumbnailAttId): array {
    $pdo = sipcons_db();
    $postmeta = sipcons_tabla('postmeta');

    $stmt = $pdo->prepare("SELECT meta_value FROM {$postmeta} WHERE post_id = :id AND meta_key = '_product_image_gallery'");
    $stmt->execute(['id' => $productId]);
    $galeriaRaw = (string)($stmt->fetch()['meta_value'] ?? '');
    $galeriaIds = $galeriaRaw !== '' ? array_map('intval', explode(',', $galeriaRaw)) : [];

    $attIds = [];
    if ($thumbnailAttId) $attIds[] = $thumbnailAttId;
    foreach ($galeriaIds as $gid) if ($gid) $attIds[] = $gid;
    $attIds = array_values(array_unique($attIds));
    if (!$attIds) return [];

    $ph = implode(',', array_fill(0, count($attIds), '?'));
    $stmt = $pdo->prepare("SELECT post_id, meta_value AS ruta FROM {$postmeta} WHERE meta_key = '_wp_attached_file' AND post_id IN ({$ph})");
    $stmt->execute($attIds);
    $rutas = [];
    foreach ($stmt as $row) $rutas[(int)$row['post_id']] = $row['ruta'];

    $stmt = $pdo->prepare("SELECT post_id, meta_value AS metadata FROM {$postmeta} WHERE meta_key = '_wp_attachment_metadata' AND post_id IN ({$ph})");
    $stmt->execute($attIds);
    $metas = [];
    foreach ($stmt as $row) {
        $datos = @unserialize($row['metadata'], ['allowed_classes' => false]);
        if (is_array($datos)) $metas[(int)$row['post_id']] = $datos;
    }

    $galeria = [];
    foreach ($attIds as $attId) {
        if (!isset($rutas[$attId])) continue;
        $rutaOriginal = $rutas[$attId];
        $sizes = $metas[$attId]['sizes'] ?? null;
        $chica = null;
        foreach (['woocommerce_thumbnail', 'medium', 'shop_catalog', 'thumbnail'] as $preferida) {
            if (isset($sizes[$preferida]['file'])) {
                $dir = dirname($rutaOriginal);
                $dir = $dir === '.' ? '' : $dir . '/';
                $chica = SIPCONS_UPLOADS_BASE . $dir . $sizes[$preferida]['file'];
                break;
            }
        }
        $grande = SIPCONS_UPLOADS_BASE . $rutaOriginal;
        $galeria[] = ['chica' => $chica ?? $grande, 'grande' => $grande];
    }
    return $galeria;
}

/**
 * Descripción larga (post_content) de un producto, para su página de detalle.
 * Conserva un set chico de etiquetas de formato; quita todo lo demás
 * (scripts, shortcodes de builders de página, etc.).
 */
function sipcons_obtener_descripcion_larga(int $productId): string {
    $pdo = sipcons_db();
    $posts = sipcons_tabla('posts');

    $stmt = $pdo->prepare("SELECT post_content FROM {$posts} WHERE ID = :id");
    $stmt->execute(['id' => $productId]);
    $contenido = (string)($stmt->fetch()['post_content'] ?? '');

    $contenido = preg_replace('/\[[^\]]*\]/', '', $contenido); // quita shortcodes [tipo_esto]
    $contenido = strip_tags($contenido, '<p><br><strong><em><b><i><ul><ol><li>');
    // strip_tags no quita atributos de las etiquetas permitidas (ej. onclick=...);
    // ninguna de ellas necesita atributos para el formato básico, así que se eliminan todos.
    $contenido = preg_replace('/<(\w+)[^>]*>/', '<$1>', $contenido);
    return trim($contenido);
}

/**
 * Busca el "código de modelo" de un producto a partir de su título: el último
 * token que trae un dígito (ej. "RHINO BAPRE-2600" -> "BAPRE2600"). Se usa
 * solo como pista para emparejar PDFs sueltos; null si no hay nada así.
 */
function sipcons_extraer_codigo_modelo(string $titulo): ?string {
    $tokens = preg_split('/\s+/', trim($titulo));
    for ($i = count($tokens) - 1; $i >= 0; $i--) {
        $t = $tokens[$i];
        if (preg_match('/\d/', $t) && strlen($t) >= 3) {
            return strtoupper((string)preg_replace('/[^A-Z0-9]/i', '', $t));
        }
    }
    return null;
}

/**
 * Fichas técnicas / manuales (PDF) de un producto.
 * 1) PDFs adjuntos directamente en WordPress (post_parent = producto): siempre confiables.
 * 2) Si no hay ninguno, busca entre los PDFs sueltos (post_parent = 0) por el
 *    código de modelo del producto — solo se usa si hay UNA sola coincidencia,
 *    para no arriesgarse a mostrar la ficha de otro equipo.
 */
function sipcons_obtener_pdfs_producto(int $productId, string $titulo): array {
    $pdo = sipcons_db();
    $posts = sipcons_tabla('posts');
    $postmeta = sipcons_tabla('postmeta');

    $stmt = $pdo->prepare("
        SELECT a.post_title AS titulo, am.meta_value AS ruta
        FROM {$posts} a
        LEFT JOIN {$postmeta} am ON am.post_id = a.ID AND am.meta_key = '_wp_attached_file'
        WHERE a.post_type = 'attachment' AND a.post_mime_type = 'application/pdf' AND a.post_parent = :id
        ORDER BY a.ID
    ");
    $stmt->execute(['id' => $productId]);
    $pdfs = [];
    foreach ($stmt as $row) {
        if ($row['ruta']) $pdfs[] = ['titulo' => $row['titulo'], 'url' => SIPCONS_UPLOADS_BASE . $row['ruta']];
    }
    if ($pdfs) return $pdfs;

    $codigo = sipcons_extraer_codigo_modelo($titulo);
    if (!$codigo) return [];

    $stmt = $pdo->query("
        SELECT a.post_title AS titulo, am.meta_value AS ruta
        FROM {$posts} a
        LEFT JOIN {$postmeta} am ON am.post_id = a.ID AND am.meta_key = '_wp_attached_file'
        WHERE a.post_type = 'attachment' AND a.post_mime_type = 'application/pdf' AND a.post_parent = 0
    ");
    $candidatos = [];
    foreach ($stmt as $row) {
        if (!$row['ruta']) continue;
        $normalizada = strtoupper((string)preg_replace('/[^A-Z0-9]/i', '', $row['ruta']));
        if (strpos($normalizada, $codigo) !== false) {
            $candidatos[] = ['titulo' => $row['titulo'], 'url' => SIPCONS_UPLOADS_BASE . $row['ruta']];
        }
    }
    return count($candidatos) === 1 ? $candidatos : [];
}
