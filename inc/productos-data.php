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
    if ($attachmentIds) {
        $attachmentIds = array_unique($attachmentIds);
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
    }

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

        $imagen = null;
        $attId = $thumbIdPorProducto[$id] ?? null;
        if ($attId && isset($rutaPorAttachment[$attId])) {
            $imagen = SIPCONS_UPLOADS_BASE . $rutaPorAttachment[$attId];
        }

        $productos[] = [
            'id'          => $id,
            'titulo'      => $p['post_title'],
            'slug'        => $p['post_name'],
            'descripcion' => trim(preg_replace('/\s+/', ' ', strip_tags((string)$p['post_excerpt']))),
            'imagen'      => $imagen,
            'tipo_slug'   => $tipoSlug,
            'tipo_label'  => $tipoSlug ? $mapa['tipos'][$tipoSlug] : 'Otros equipos',
            'marca_slug'  => $marcaSlug,
            'marca_label' => $marcaSlug ? $mapa['marcas'][$marcaSlug] : '',
        ];
    }

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
        if (isset($mapa['marcas_con_pagina'][$slug])) continue; // esas van como link, no como chip
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
