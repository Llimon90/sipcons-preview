<?php
/**
 * Página de detalle de un producto individual, alimentada en vivo desde
 * WooCommerce (sipcons1_basedatos, solo lectura). Ver inc/productos-data.php.
 * URL: producto.php?slug=<post_name del producto en WordPress>
 */
declare(strict_types=1);
error_reporting(0);

require_once __DIR__ . '/inc/productos-data.php';

$slug = trim((string)($_GET['slug'] ?? ''));
$producto = null;
$relacionados = [];
$descripcionLarga = '';
$pdfs = [];
$galeria = [];
$errorCatalogo = false;

try {
    $catalogo = sipcons_obtener_productos();
    foreach ($catalogo['productos'] as $p) {
        if ($p['slug'] === $slug) { $producto = $p; break; }
    }
    if ($producto) {
        $descripcionLarga = sipcons_obtener_descripcion_larga($producto['id']);
        $pdfs = sipcons_obtener_pdfs_producto($producto['id'], $producto['titulo']);
        $galeria = sipcons_obtener_galeria_producto($producto['id'], $producto['imagen_id']);
        if (!$galeria && $producto['imagen_grande']) {
            $galeria = [['chica' => $producto['imagen'] ?? $producto['imagen_grande'], 'grande' => $producto['imagen_grande']]];
        }

        foreach ($catalogo['productos'] as $p) {
            if ($p['id'] === $producto['id']) continue;
            $mismoTipo  = $p['tipo_slug'] && $p['tipo_slug'] === $producto['tipo_slug'];
            $mismaMarca = $p['marca_slug'] && $p['marca_slug'] === $producto['marca_slug'];
            if ($mismoTipo || $mismaMarca) $relacionados[] = $p;
            if (count($relacionados) >= 4) break;
        }
    }
} catch (Throwable $e) {
    $errorCatalogo = true;
}

if (!$producto && !$errorCatalogo) {
    http_response_code(404);
    header('Location: productos.php');
    exit;
}

$e = static fn(?string $s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$tituloPagina = $producto ? $producto['titulo'] . ' · SIPCONS' : 'Producto no disponible · SIPCONS';
$descMeta = $producto
    ? ($producto['descripcion'] !== '' ? $producto['descripcion'] : ($producto['tipo_label'] . ($producto['marca_label'] !== '' ? ' ' . $producto['marca_label'] : '')) . '. Cotización a la medida en Baja California.')
    : 'Catálogo de básculas y puntos de venta SIPCONS.';
$ogImagen = ($producto && $producto['imagen_grande']) ? $producto['imagen_grande'] : 'https://sipcons.com/assets/img/hero-slide-1.jpg';
$urlCanonica = 'https://sipcons.com/producto.php' . ($producto ? '?slug=' . rawurlencode($producto['slug']) : '');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($tituloPagina) ?></title>
<meta name="description" content="<?= $e($descMeta) ?>">
<link rel="canonical" href="<?= $e($urlCanonica) ?>">
<meta name="theme-color" content="#0E1B3D">
<link rel="icon" href="./assets/img/favicon.ico" sizes="any">
<link rel="icon" type="image/png" href="./assets/img/favicon-32.png">
<link rel="apple-touch-icon" href="./assets/img/apple-touch-icon.png">
<meta property="og:type" content="product">
<meta property="og:site_name" content="SIPCONS">
<meta property="og:locale" content="es_MX">
<meta property="og:title" content="<?= $e($tituloPagina) ?>">
<meta property="og:description" content="<?= $e($descMeta) ?>">
<meta property="og:url" content="<?= $e($urlCanonica) ?>">
<meta property="og:image" content="<?= $e($ogImagen) ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= $e($tituloPagina) ?>">
<meta name="twitter:description" content="<?= $e($descMeta) ?>">
<meta name="twitter:image" content="<?= $e($ogImagen) ?>">
<link rel="stylesheet" href="./assets/css/site.css?v=20260924d">
</head>
<body>

<div class="home-brandbar">
  <a href="index.php" class="home-brand" aria-label="SIPCONS — inicio">
    <img src="./assets/img/logo-grande.png" alt="SIPCONS · Soluciones Integrales de Pesaje y Control" width="1049" height="290">
  </a>
</div>


<header class="nav" id="nav">
  <div class="wrap nav-inner">
    <a href="index.php" class="brand"><img src="./assets/img/logo-grande.png" alt="SIPCONS" class="nav-logo"></a>
    <nav><ul class="nav-links">
      <li><a href="index.php">Inicio</a></li>
      <li><a href="quienes-somos.html">Nosotros</a></li>
      <li><a href="servicios.html">Servicios</a></li>
      <li><a href="productos.php" class="active">Productos</a></li>
      <li><a href="soporte.html">Soporte</a></li>
      <li><a href="contacto.html">Contacto</a></li>
    </ul></nav>
    <div class="nav-cta">
      <a href="contacto.html" class="btn btn-ghost-light">Contáctanos</a>
      <button class="nav-toggle" id="navToggle" aria-label="Abrir menú" aria-expanded="false"><span></span><span></span><span></span></button>
    </div>
  </div>
</header>

<?php if (!$producto): ?>

<section class="page-hero">
  <div class="hero-orb a"></div>
  <div class="hero-grid"></div>
  <div class="wrap page-hero-inner">
    <nav class="breadcrumb"><a href="index.php">Inicio</a> / <a href="productos.php">Productos</a> / <span>No disponible</span></nav>
    <h1 class="reveal">El catálogo no está disponible en este momento</h1>
    <p class="sub reveal" data-delay="1">Escríbenos por WhatsApp y con gusto te cotizamos el equipo que buscas.</p>
  </div>
</section>
<section class="section">
  <div class="wrap" style="text-align:center">
    <a href="https://wa.me/526641086038" class="btn btn-action">Escríbenos por WhatsApp <span class="arw">→</span></a>
    <a href="productos.php" class="btn btn-outline" style="margin-left:var(--space-3)">Volver al catálogo</a>
  </div>
</section>

<?php else: ?>

<section class="page-hero">
  <div class="hero-orb a"></div>
  <div class="hero-grid"></div>
  <div class="wrap page-hero-inner">
    <nav class="breadcrumb"><a href="index.php">Inicio</a> / <a href="productos.php">Productos</a> / <span><?= $e($producto['titulo']) ?></span></nav>
    <span class="overline" style="color:var(--sip-cyan-400)"><?= $e($producto['tipo_label']) ?><?= $producto['marca_label'] !== '' ? ' · ' . $e($producto['marca_label']) : '' ?></span>
    <h1 class="reveal"><?= $e($producto['titulo']) ?></h1>
  </div>
</section>

<section class="section">
  <div class="wrap">

    <div class="product-media-row<?= !$pdfs ? ' single' : '' ?> reveal">
      <?php if ($galeria): ?>
      <div class="product-gallery" id="productGallery">
        <div class="product-gallery-main" id="galleryMain">
          <img id="galleryMainImg" src="<?= $e($galeria[0]['grande']) ?>" data-full="<?= $e($galeria[0]['grande']) ?>" alt="<?= $e($producto['titulo']) ?>" loading="eager">
          <span class="gallery-zoom-hint" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3M11 8v6M8 11h6"/></svg>
          </span>
        </div>
        <?php if (count($galeria) > 1): ?>
        <div class="product-gallery-thumbs">
          <?php foreach ($galeria as $i => $g): ?>
          <button type="button" class="<?= $i === 0 ? 'active' : '' ?>" data-grande="<?= $e($g['grande']) ?>" aria-label="Ver foto <?= $i + 1 ?> de <?= count($galeria) ?>">
            <img src="<?= $e($g['chica']) ?>" alt="" loading="lazy">
          </button>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      <?php else: ?>
      <div class="product-gallery">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2"><rect x="4" y="7" width="16" height="12" rx="2"/><path d="M8 7V5h8v2M12 11v4M10 13h4"/></svg>
      </div>
      <?php endif; ?>

      <?php if ($pdfs): ?>
      <div class="product-pdf-viewer">
        <iframe src="<?= $e($pdfs[0]['url']) ?>" title="Ficha técnica — <?= $e($producto['titulo']) ?>" loading="lazy"></iframe>
        <div class="pdf-toolbar">
          <span>Ficha técnica (PDF)</span>
          <span>
            <a href="<?= $e($pdfs[0]['url']) ?>" target="_blank" rel="noopener">Ver en grande ↗</a>
            <a href="<?= $e($pdfs[0]['url']) ?>" download>Descargar</a>
          </span>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <?php if ($galeria): ?>
    <div class="gallery-modal" id="galleryModal">
      <button type="button" class="gallery-modal-close" id="galleryModalClose" aria-label="Cerrar">&times;</button>
      <?php if (count($galeria) > 1): ?>
      <button type="button" class="gallery-modal-nav prev" id="galleryModalPrev" aria-label="Foto anterior">‹</button>
      <button type="button" class="gallery-modal-nav next" id="galleryModalNext" aria-label="Foto siguiente">›</button>
      <?php endif; ?>
      <img id="galleryModalImg" src="" alt="<?= $e($producto['titulo']) ?>">
    </div>
    <?php endif; ?>

    <div class="product-detail-body reveal" data-delay="1" style="max-width:760px;margin-top:var(--space-8)">
      <span class="resource-tag"><?= $e($producto['tipo_label']) ?></span>
      <?php if ($producto['descripcion'] !== ''): ?>
      <p style="color:var(--color-text-muted);margin-top:var(--space-3)"><?= $e($producto['descripcion']) ?></p>
      <?php endif; ?>
      <p class="price-note">Cotización a la medida de acuerdo a tus requerimientos y necesidades.</p>
      <div class="btn-row" style="margin-top:var(--space-5)">
        <a href="https://wa.me/526641086038?text=<?= rawurlencode('Hola, quiero cotizar: ' . $producto['titulo']) ?>" class="btn btn-action">Cotizar por WhatsApp <span class="arw">→</span></a>
        <a href="contacto.html" class="btn btn-outline">Hablar con ventas</a>
      </div>
      <?php if (count($pdfs) > 1): foreach (array_slice($pdfs, 1) as $pdf): ?>
      <div style="margin-top:var(--space-4)">
        <a href="<?= $e($pdf['url']) ?>" target="_blank" rel="noopener" class="btn btn-outline btn-sm">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="width:16px;height:16px;margin-right:6px;vertical-align:-3px"><path d="M12 3v12m0 0 4-4m-4 4-4-4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></svg>
          <?= $e($pdf['titulo'] ?: 'Documento adicional (PDF)') ?>
        </a>
      </div>
      <?php endforeach; endif; ?>
      <?php if ($descripcionLarga !== ''): ?>
      <div style="margin-top:var(--space-6);color:var(--color-text-muted);font-size:var(--text-sm);line-height:var(--leading-relaxed)"><?= $descripcionLarga ?></div>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php if ($relacionados): ?>
<section class="section subtle">
  <div class="wrap">
    <div class="section-head reveal"><span class="overline">También te puede interesar</span><h2 style="font-size:var(--text-2xl)">Productos relacionados</h2></div>
    <div class="related-grid">
      <?php foreach ($relacionados as $i => $r): ?>
      <article class="product reveal" <?= $i > 0 ? 'data-delay="' . $i . '"' : '' ?>>
        <div class="product-img">
          <span class="product-cat"><?= $e($r['marca_label'] !== '' ? $r['marca_label'] : $r['tipo_label']) ?></span>
          <?php if ($r['imagen']): ?>
          <img src="<?= $e($r['imagen']) ?>" alt="<?= $e($r['titulo']) ?>" loading="lazy">
          <?php else: ?>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2"><rect x="4" y="7" width="16" height="12" rx="2"/><path d="M8 7V5h8v2M12 11v4M10 13h4"/></svg>
          <?php endif; ?>
        </div>
        <div class="product-body">
          <h3><?= $e($r['titulo']) ?></h3>
          <div class="product-actions"><a href="producto.php?slug=<?= rawurlencode($r['slug']) ?>" class="btn btn-outline btn-sm">Ver ficha</a></div>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php endif; ?>

<footer class="footer">
  <div class="wrap">
    <div class="footer-grid">
      <div><div class="brand"><img src="./assets/img/logo-grande.png" alt="SIPCONS" class="nav-logo"></div><p class="tagline">Soluciones Integrales de Pesaje y Control. Innovación y confianza desde 1980.</p></div>
      <div><h5>Soluciones</h5><ul>
        <li><a href="productos.php">Básculas</a></li><li><a href="productos.php?grupo=pos#catalog">Puntos de Venta</a></li>
        <li><a href="productos.php">Plataformas</a></li><li><a href="productos.php?grupo=consumibles#catalog">Consumibles</a></li><li><a href="soporte.html">Soporte técnico</a></li>
      </ul></div>
      <div><h5>Recursos</h5><ul>
        <li><a href="casos-de-exito.html">Casos de éxito</a></li><li><a href="recursos.html">Centro de recursos</a></li>
        <li><a href="calibracion.html">Calibración</a></li><li><a href="soporte.html#faq">Preguntas frecuentes</a></li>
      </ul></div>
      <div><h5>Contacto</h5><ul>
        <li><a href="tel:+526646300471">(664) 630-0471</a></li><li><a href="mailto:info@sipcons.com">info@sipcons.com</a></li>
        <li>Av. De Las Perlas 630, Playas de Tijuana, B.C.</li><li>Lun–Vie 08:30–18:00 · Sáb 09:00–13:30</li><li>Guardias y emergencias 24/7</li><li class="footer-social"><a href="https://www.facebook.com/profile.php?id=100075871217682" target="_blank" rel="noopener" aria-label="Facebook de SIPCONS"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M24 12.07C24 5.4 18.63 0 12 0S0 5.4 0 12.07C0 18.1 4.39 23.1 10.13 24v-8.44H7.08v-3.49h3.05V9.41c0-3.02 1.79-4.69 4.53-4.69 1.31 0 2.68.24 2.68.24v2.97h-1.51c-1.49 0-1.96.93-1.96 1.89v2.26h3.33l-.53 3.49h-2.8V24C19.61 23.1 24 18.1 24 12.07z"/></svg></a><a href="https://www.instagram.com/sipcons" target="_blank" rel="noopener" aria-label="Instagram de SIPCONS"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2.16c3.2 0 3.58.01 4.85.07 1.17.05 1.8.25 2.23.41.56.22.96.48 1.38.9.42.42.68.82.9 1.38.16.42.36 1.06.41 2.23.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.05 1.17-.25 1.8-.41 2.23-.22.56-.48.96-.9 1.38-.42.42-.82.68-1.38.9-.42.16-1.06.36-2.23.41-1.27.06-1.65.07-4.85.07s-3.58-.01-4.85-.07c-1.17-.05-1.8-.25-2.23-.41a3.7 3.7 0 0 1-1.38-.9 3.7 3.7 0 0 1-.9-1.38c-.16-.42-.36-1.06-.41-2.23-.06-1.27-.07-1.65-.07-4.85s.01-3.58.07-4.85c.05-1.17.25-1.8.41-2.23.22-.56.48-.96.9-1.38.42-.42.82-.68 1.38-.9.42-.16 1.06-.36 2.23-.41 1.27-.06 1.65-.07 4.85-.07M12 0C8.74 0 8.33.01 7.05.07 5.78.13 4.9.33 4.14.63c-.79.3-1.46.72-2.13 1.38C1.35 2.68.93 3.35.63 4.14.33 4.9.13 5.78.07 7.05.01 8.33 0 8.74 0 12s.01 3.67.07 4.95c.06 1.27.26 2.15.56 2.91.3.79.72 1.46 1.38 2.13.67.66 1.34 1.08 2.13 1.38.76.3 1.64.5 2.91.56C8.33 23.99 8.74 24 12 24s3.67-.01 4.95-.07c1.27-.06 2.15-.26 2.91-.56.79-.3 1.46-.72 2.13-1.38.66-.67 1.08-1.34 1.38-2.13.3-.76.5-1.64.56-2.91.06-1.28.07-1.69.07-4.95s-.01-3.67-.07-4.95c-.06-1.27-.26-2.15-.56-2.91-.3-.79-.72-1.46-1.38-2.13-.67-.66-1.34-1.08-2.13-1.38-.76-.3-1.64-.5-2.91-.56C15.67.01 15.26 0 12 0z"/><path d="M12 5.84A6.16 6.16 0 1 0 18.16 12 6.16 6.16 0 0 0 12 5.84zM12 16a4 4 0 1 1 4-4 4 4 0 0 1-4 4z"/><circle cx="18.41" cy="5.59" r="1.44"/></svg></a></li>
      </ul></div>
    </div>
    <div class="copy">© 2026 SIPCONS. Todos los derechos reservados.</div>
  </div>
</footer>

<a href="https://wa.me/526641086038" class="wa" aria-label="Escríbenos por WhatsApp"><svg viewBox="0 0 24 24"><path d="M17.5 14.4c-.3-.1-1.7-.8-2-.9-.3-.1-.5-.1-.7.1-.2.3-.7.9-.9 1.1-.2.2-.3.2-.6.1-.3-.1-1.2-.5-2.3-1.4-.9-.8-1.4-1.7-1.6-2-.2-.3 0-.5.1-.6l.4-.5c.1-.2.2-.3.3-.5.1-.2 0-.4 0-.5s-.7-1.6-.9-2.2c-.2-.6-.5-.5-.7-.5h-.6c-.2 0-.5.1-.8.4-.3.3-1 1-1 2.5s1.1 2.9 1.2 3.1c.1.2 2.1 3.2 5 4.5.7.3 1.3.5 1.7.6.7.2 1.4.2 1.9.1.6-.1 1.7-.7 1.9-1.4.2-.7.2-1.2.2-1.4-.1-.1-.3-.2-.6-.3M12 2a10 10 0 0 0-8.6 15l-1.3 4.8 4.9-1.3A10 10 0 1 0 12 2Z"/></svg></a>

<script src="./assets/js/site.js?v=20260924d"></script>
</body>
</html>
