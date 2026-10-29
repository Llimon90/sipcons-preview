<?php
/**
 * SIPCONS · panel de analítica: contactos (formulario, WhatsApp, teléfono,
 * correo) con su contexto, actividad en vivo, salud del formulario, fuentes,
 * dispositivos, páginas, productos, catálogo, horarios y estado del servidor.
 *
 * Abrir como /inc/stats.php?token=... (el 'stats_token' de inc/db-config.php).
 * Opciones: &dias=1|7|30|90|365 · &desde=AAAA-MM-DD&hasta=AAAA-MM-DD (rango a la
 * medida) · &pruebas=0 (excluir envíos de prueba interna) · &export=csv.
 */

declare(strict_types=1);
error_reporting(0);

$config = is_file(__DIR__ . '/db-config.php') ? require __DIR__ . '/db-config.php' : [];
$token  = (string)($config['stats_token'] ?? '');
if ($token === '' || !hash_equals($token, (string)($_GET['token'] ?? ''))) {
    http_response_code(403);
    exit('Acceso denegado.');
}

require __DIR__ . '/analitica.php';
date_default_timezone_set('America/Tijuana');

// ---------------- Periodo ----------------
$ahora = time();
$hoy0  = strtotime('today');
$fechaValida = static function ($f): ?int {
    if (!is_string($f) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)) return null;
    $t = strtotime($f . ' 00:00:00');
    return $t !== false && date('Y-m-d', $t) === $f ? $t : null;
};
$desdeA = $fechaValida($_GET['desde'] ?? null);
$hastaA = $fechaValida($_GET['hasta'] ?? null);
$aMedida = $desdeA !== null && $hastaA !== null && $desdeA <= $hastaA && $desdeA <= $hoy0;
if ($aMedida) {
    $hastaA = min($hastaA, $hoy0);
    $desde  = $desdeA;
    $hasta  = $hastaA === $hoy0 ? $ahora : strtotime('+1 day', $hastaA) - 1;
    $dias   = (int)round(($hastaA - $desdeA) / 86400) + 1;
} else {
    $dias = (int)($_GET['dias'] ?? 30);
    if (!in_array($dias, [1, 7, 30, 90, 365], true)) $dias = 30;
    $desde = strtotime('-' . ($dias - 1) . ' days', $hoy0);
    $hasta = $ahora;
}
$ultimoDia0 = strtotime(date('Y-m-d', $hasta));
$desdePrev  = strtotime('-' . $dias . ' days', $desde);
$hastaPrev  = $desde - 1;
$conPruebas = ($_GET['pruebas'] ?? '1') !== '0';
$porHoras   = $dias === 1;

// Enlaces del panel conservando token, periodo y filtros.
$params = ['token' => $token];
if ($aMedida) { $params['desde'] = date('Y-m-d', $desde); $params['hasta'] = date('Y-m-d', $ultimoDia0); }
else $params['dias'] = $dias;
if (!$conPruebas) $params['pruebas'] = '0';
$url = static function (array $cambios = []) use ($params): string {
    $q = array_filter(array_merge($params, $cambios), static fn($v) => $v !== null);
    return '?' . http_build_query($q, '', '&amp;');
};

// ---------------- Exportación CSV ----------------
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="sipcons-eventos-' . date('Y-m-d', $desde) . '_' . date('Y-m-d', $hasta) . '.csv"');
    header('X-Robots-Tag: noindex, nofollow');
    header('Referrer-Policy: no-referrer');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['fecha', 'evento', 'pagina', 'sesion', 'visitante', 'dispositivo', 'navegador', 'sistema', 'origen', 'utm_source', 'utm_medium', 'utm_campaign', 'lugar_o_motivo', 'producto', 'texto', 'resultados', 'interes', 'ms_activos', 'scroll_pct', 'prueba_interna']);
    // Textos que escribe el visitante (búsquedas, origen, etc.) pueden empezar con
    // = + - @ y Excel los ejecutaría como fórmula al abrir el CSV; se neutralizan
    // anteponiendo un apóstrofo.
    $celda = static fn($v): string => preg_match('/^[=+\-@\t\r]/', (string)$v) ? "'" . $v : (string)$v;
    foreach (sipcons_leer_eventos($desde, $hasta) as $ev) {
        if (!$conPruebas && !empty($ev['x'])) continue;
        fputcsv($out, array_map($celda, [
            $ev['t'], $ev['e'], $ev['p'] ?? '', $ev['s'] ?? '', $ev['v'] ?? '', $ev['d'] ?? '', $ev['b'] ?? '', $ev['o'] ?? '',
            $ev['r'] ?? '', $ev['us'] ?? '', $ev['um'] ?? '', $ev['uc'] ?? '', $ev['lg'] ?? '', $ev['pr'] ?? '', $ev['q'] ?? '',
            $ev['rs'] ?? '', $ev['i'] ?? '', $ev['ms'] ?? '', $ev['sc'] ?? '', !empty($ev['x']) ? 'sí' : '',
        ]));
    }
    fclose($out);
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer'); // el token va en la URL: que no salga en el Referer

$R = sipcons_analizar($desde, $hasta, $conPruebas);
$P = sipcons_analizar($desdePrev, $hastaPrev, $conPruebas);
// «Ahora mismo» siempre se calcula sobre la última media hora, sin importar el periodo elegido.
$V = $aMedida && $hasta < $ahora ? sipcons_analizar($ahora - SIPCONS_VENTANA_VIVO, $ahora, $conPruebas) : $R;

// Nombres legibles de producto (slug -> título) desde el catálogo; si falla, se usa el slug.
$titulos = [];
try {
    require_once __DIR__ . '/productos-data.php';
    foreach (sipcons_obtener_productos()['productos'] as $prod) $titulos[$prod['slug']] = $prod['titulo'];
} catch (Throwable $e) {
}

// ---------------- Utilidades de formato ----------------
$h   = static fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$nf  = static fn($n): string => number_format((float)$n, 0, '.', ',');
$pct = static fn($a, $b, int $dec = 1): string => $b > 0 ? number_format($a / $b * 100, $dec, '.', '') . '%' : '—';
$dur = static function (float $ms): string {
    $s = (int)round($ms / 1000);
    if ($s < 60) return $s . ' s';
    if ($s < 3600) return intdiv($s, 60) . ' min ' . ($s % 60) . ' s';
    return intdiv($s, 3600) . ' h ' . intdiv($s % 3600, 60) . ' min';
};
$meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
$diasSem = [1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb', 7 => 'Dom'];
$fechaCorta = static fn(int $t): string => (int)date('j', $t) . ' ' . $meses[(int)date('n', $t) - 1];
$fechaHora = static fn(int $t): string => $diasSem[(int)date('N', $t)] . ' ' . (int)date('j', $t) . ' ' . $meses[(int)date('n', $t) - 1] . ' · ' . date('H:i', $t);
$hace = static function (?int $t) use ($ahora): string {
    if (!$t) return 'nunca';
    $m = (int)floor(max(0, $ahora - $t) / 60);
    if ($m < 1) return 'hace un momento';
    if ($m < 60) return 'hace ' . $m . ' min';
    if ($m < 1440) return 'hace ' . intdiv($m, 60) . ' h';
    return 'hace ' . intdiv($m, 1440) . ' días';
};
$nombreProducto = static fn(string $slug): string => $titulos[$slug] ?? strtoupper(str_replace('-', ' ', $slug));
$nombrePagina = static function (string $p) use ($nombreProducto): string {
    if ($p === '' || $p === '/') return 'Inicio';
    $slug = sipcons_slug_de_pagina($p);
    if ($slug !== '') return 'Ficha: ' . $nombreProducto($slug);
    $fijas = ['/productos.php' => 'Catálogo', '/contacto.html' => 'Contacto', '/servicios.html' => 'Servicios', '/soporte.html' => 'Soporte',
              '/quienes-somos.html' => 'Nosotros', '/producto-mr-tienda.html' => 'Ficha: Mr. Tienda®'];
    return $fijas[strtok($p, '?')] ?? $p;
};
$contactosDe = static fn(array $r): int => (int)array_sum(array_intersect_key($r['por_tipo'], array_flip(SIPCONS_CONTACTOS)));
$etPeriodo = $porHoras ? 'el día anterior' : 'los ' . $dias . ' días previos';
$delta = static function ($actual, $previo, bool $subirEsBueno = true) use ($etPeriodo): string {
    if ($previo <= 0) return $actual > 0 ? '<span class="delta neutro">nuevo vs ' . $etPeriodo . '</span>' : '';
    $c = ($actual - $previo) / $previo * 100;
    if (abs($c) < 0.5) return '<span class="delta neutro">= sin cambio vs ' . $etPeriodo . '</span>';
    $sube = $c > 0;
    $bueno = $sube === $subirEsBueno;
    return '<span class="delta ' . ($bueno ? 'bien' : 'mal') . '">' . ($sube ? '▲ ' : '▼ ') . number_format(abs($c), 0) . '% <small>vs ' . $etPeriodo . '</small></span>';
};
$nombresCanal = ['formulario' => 'Formulario', 'whatsapp' => 'WhatsApp', 'telefono' => 'Llamada', 'correo' => 'Correo'];
$nombresEvento = [
    'vista' => 'Vio la página', 'whatsapp' => 'Clic en WhatsApp', 'telefono' => 'Clic en llamar', 'correo' => 'Clic en correo',
    'formulario' => 'Envió el formulario', 'form_inicio' => 'Empezó el formulario', 'form_error' => 'Formulario no enviado',
    'filtro' => 'Usó un filtro', 'busqueda' => 'Buscó en el catálogo', 'pdf' => 'Abrió ficha PDF', 'galeria' => 'Vio la galería',
    'saliente' => 'Salió a otro sitio', 'destacado' => 'Clic en destacado', 'error404' => 'Página no encontrada',
];

$T = $R['totales'];
$TP = $P['totales'];
$contactos = $contactosDe($R);
$contactosPrev = $contactosDe($P);
$conv = $T['sesiones'] > 0 ? $T['con_contacto'] / $T['sesiones'] * 100 : 0.0;
$convPrev = $TP['sesiones'] > 0 ? $TP['con_contacto'] / $TP['sesiones'] * 100 : 0.0;
$rebote = $T['sesiones'] > 0 ? $T['rebotes'] / $T['sesiones'] * 100 : 0.0;
$rebotePrev = $TP['sesiones'] > 0 ? $TP['rebotes'] / $TP['sesiones'] * 100 : 0.0;
$msProm = $T['con_ms'] > 0 ? $T['ms'] / $T['con_ms'] : 0;
$pagsPorSesion = $T['sesiones'] > 0 ? $T['vistas'] / $T['sesiones'] : 0;
$hoyClave = date('Y-m-d');

$formIni = (int)($R['por_tipo']['form_inicio'] ?? 0);
$formOk  = (int)($R['por_tipo']['formulario'] ?? 0);
$formMal = (int)array_sum($R['errores_form']);
$formMalReal = $formMal - (int)($R['errores_form']['bot (honeypot)'] ?? 0) - (int)($R['errores_form']['otro sitio'] ?? 0);
$correoFallido = (int)($R['errores_form']['falló el envío del correo'] ?? 0);

// ---------------- Estado del servidor y del registro ----------------
$logActual = __DIR__ . '/eventos-' . date('Y-m') . '.log';
$dirEscribible = is_writable(__DIR__);
$logExiste = is_file($logActual);
$logTam = $logExiste ? (int)filesize($logActual) : 0;
$logTope = defined('SIPCONS_EVENTOS_MAX_BYTES') ? SIPCONS_EVENTOS_MAX_BYTES : 40 * 1024 * 1024;
$ultimoEvento = null;
if ($logExiste && $logTam > 0) {
    $fhu = fopen($logActual, 'r');
    fseek($fhu, max(0, $logTam - 4096));
    $cola = trim((string)stream_get_contents($fhu));
    fclose($fhu);
    $ultimaLinea = substr($cola, (int)strrpos($cola, "\n"));
    $evU = json_decode(trim($ultimaLinea), true);
    if (is_array($evU) && isset($evU['t'])) $ultimoEvento = strtotime((string)$evU['t']) ?: null;
}
$enviosUltimaHora = 0;
$limiteArchivo = __DIR__ . '/contacto-limite.dat';
if (is_file($limiteArchivo)) {
    $lim = json_decode((string)@file_get_contents($limiteArchivo), true);
    if (is_array($lim)) foreach ($lim as $r) if (is_array($r) && ($r['t'] ?? 0) > $ahora - 3600) $enviosUltimaHora++;
}
$eventosRango = (int)array_sum($R['por_tipo']);

// php2shell (CVE-2026-109618 / CVE-2026-109619): versiones con parche oficial.
$parches = ['8.5' => '8.5.12', '8.4' => '8.4.27', '8.3' => '8.3.36', '8.2' => '8.2.35'];
$rama = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
if (isset($parches[$rama])) $phpVulnerable = version_compare(PHP_VERSION, $parches[$rama], '<');
else $phpVulnerable = version_compare(PHP_VERSION, '8.2.0', '<');
$phpParche = $parches[$rama] ?? '8.2.35 o superior';
$uploadProgress = (string)ini_get('session.upload_progress.enabled');
$uploadProgressOn = !in_array(strtolower($uploadProgress), ['', '0', 'off', 'false'], true);

// ---------------- Series (por día, o por hora si el periodo es de un día) ----------------
$etiquetas = [];
if ($porHoras) {
    $horaFin = (int)date('G', $hasta);
    for ($hh = 0; $hh <= $horaFin; $hh++) $etiquetas[] = date('Y-m-d', $desde) . ' ' . str_pad((string)$hh, 2, '0', STR_PAD_LEFT);
    $fuenteSerie = $R['por_hora'];
    $fmtEtiqueta = static fn(string $k): string => (int)substr($k, 11, 2) . ':00';
} else {
    for ($t = $desde; $t <= $ultimoDia0; $t = strtotime('+1 day', $t)) $etiquetas[] = date('Y-m-d', $t);
    $fuenteSerie = $R['por_dia'];
    $fmtEtiqueta = static fn(string $f): string => (int)substr($f, 8, 2) . ' ' . $meses[(int)substr($f, 5, 2) - 1];
}
$serieVistas = [];
$serieContactos = ['formulario' => [], 'telefono' => [], 'whatsapp' => [], 'correo' => []];
foreach ($etiquetas as $k) {
    $serieVistas[] = (int)($fuenteSerie[$k]['vistas'] ?? 0);
    foreach ($serieContactos as $c => $_) $serieContactos[$c][] = (int)($fuenteSerie[$k][$c] ?? 0);
}
$hoyDatos = $R['por_dia'][$hoyClave] ?? [];
$contactosHoy = 0;
foreach (SIPCONS_CONTACTOS as $c) $contactosHoy += (int)($hoyDatos[$c] ?? 0);

function techo_bonito(float $v): float {
    // Techo = 4 divisiones enteras y "redondas" (p. ej. 40 -> 0/10/20/30/40).
    if ($v <= 4) return 4;
    $bruto = $v / 4;
    $base = 10 ** floor(log10($bruto));
    foreach ([1, 2, 5, 10] as $m) if ($bruto <= $m * $base) return 4 * $m * $base;
    return 40 * $base;
}

/** Gráfica de línea con área (una serie). */
function svg_linea(array $etiquetas, array $vals, string $unidad, string $idc, callable $fmt): string {
    $W = 760; $H = 220; $ml = 44; $mr = 14; $mt = 14; $mb = 28;
    $n = count($vals);
    $max = techo_bonito((float)max($vals ?: [0]));
    $pw = $W - $ml - $mr; $ph = $H - $mt - $mb;
    $x = static fn(int $i): float => $ml + ($n > 1 ? $i * $pw / ($n - 1) : $pw / 2);
    $y = static fn(float $v): float => $mt + $ph - ($max > 0 ? $v / $max * $ph : 0);
    $s = '<svg class="chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . htmlspecialchars($unidad) . ' en el periodo" data-chart="' . $idc . '">';
    for ($k = 0; $k <= 4; $k++) {
        $v = $max * $k / 4; $yy = $y($v);
        $s .= '<line class="gl" x1="' . $ml . '" x2="' . ($W - $mr) . '" y1="' . $yy . '" y2="' . $yy . '"/>';
        $s .= '<text class="tick" x="' . ($ml - 8) . '" y="' . ($yy + 4) . '" text-anchor="end">' . number_format($v, 0) . '</text>';
    }
    $paso = max(1, (int)ceil($n / 7));
    foreach ($etiquetas as $i => $f) {
        if ($i % $paso === 0) $s .= '<text class="tick" x="' . $x($i) . '" y="' . ($H - 8) . '" text-anchor="middle">' . htmlspecialchars($fmt($f)) . '</text>';
    }
    $pts = [];
    foreach ($vals as $i => $v) $pts[] = round($x($i), 1) . ',' . round($y((float)$v), 1);
    if ($n > 1) {
        $s .= '<polygon class="area" points="' . round($x(0), 1) . ',' . round($y(0), 1) . ' ' . implode(' ', $pts) . ' ' . round($x($n - 1), 1) . ',' . round($y(0), 1) . '"/>';
        $s .= '<polyline class="linea" points="' . implode(' ', $pts) . '"/>';
    }
    if ($n > 0) $s .= '<circle class="punto" cx="' . round($x($n - 1), 1) . '" cy="' . round($y((float)$vals[$n - 1]), 1) . '" r="4"/>';
    $s .= '<line class="xhair" y1="' . $mt . '" y2="' . ($mt + $ph) . '" x1="0" x2="0"/>';
    $slot = $n > 0 ? $pw / max(1, $n - ($n > 1 ? 1 : 0)) : $pw;
    foreach ($vals as $i => $v) {
        $tip = $fmt($etiquetas[$i]) . ' — ' . number_format($v) . ' ' . $unidad;
        $s .= '<rect class="hit" x="' . round($x($i) - $slot / 2, 1) . '" y="' . $mt . '" width="' . round($slot, 1) . '" height="' . $ph . '" data-x="' . round($x($i), 1) . '" data-tip="' . htmlspecialchars($tip) . '"/>';
    }
    return $s . '</svg>';
}

/** Columnas apiladas por canal (2px de separación, extremo superior redondeado). */
function svg_apiladas(array $etiquetas, array $series, array $nombres, array $clases, string $idc, callable $fmt): string {
    $W = 760; $H = 220; $ml = 44; $mr = 14; $mt = 14; $mb = 28;
    $n = count($etiquetas);
    $tot = [];
    for ($i = 0; $i < $n; $i++) { $tot[$i] = 0; foreach ($series as $v) $tot[$i] += $v[$i]; }
    $max = techo_bonito((float)max($tot ?: [0]));
    $pw = $W - $ml - $mr; $ph = $H - $mt - $mb;
    $slot = $pw / max(1, $n);
    $bw = min(24.0, max(3.0, $slot * 0.62));
    $y = static fn(float $v): float => $mt + $ph - ($max > 0 ? $v / $max * $ph : 0);
    $s = '<svg class="chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Contactos en el periodo" data-chart="' . $idc . '">';
    for ($k = 0; $k <= 4; $k++) {
        $v = $max * $k / 4; $yy = $y($v);
        $s .= '<line class="gl" x1="' . $ml . '" x2="' . ($W - $mr) . '" y1="' . $yy . '" y2="' . $yy . '"/>';
        $s .= '<text class="tick" x="' . ($ml - 8) . '" y="' . ($yy + 4) . '" text-anchor="end">' . number_format($v, 0) . '</text>';
    }
    $paso = max(1, (int)ceil($n / 7));
    for ($i = 0; $i < $n; $i++) {
        $cx = $ml + $slot * ($i + 0.5);
        if ($i % $paso === 0) $s .= '<text class="tick" x="' . round($cx, 1) . '" y="' . ($H - 8) . '" text-anchor="middle">' . htmlspecialchars($fmt($etiquetas[$i])) . '</text>';
        $acum = 0;
        $ultimoConValor = null;
        foreach ($series as $k => $v) if ($v[$i] > 0) $ultimoConValor = $k;
        foreach ($series as $k => $v) {
            $val = $v[$i];
            if ($val <= 0) continue;
            $yTop = $y($acum + $val); $yBase = $y($acum);
            $alto = max(1.0, $yBase - $yTop - 2); // 2px de aire entre segmentos
            $x0 = round($cx - $bw / 2, 1);
            $yy = round($yBase - $alto, 1);
            $r = min(3.0, $alto, $bw / 2);
            if ($k === $ultimoConValor) {
                $d = 'M' . $x0 . ',' . ($yy + $alto) . ' V' . ($yy + $r) . ' Q' . $x0 . ',' . $yy . ' ' . ($x0 + $r) . ',' . $yy . ' H' . ($x0 + $bw - $r) . ' Q' . ($x0 + $bw) . ',' . $yy . ' ' . ($x0 + $bw) . ',' . ($yy + $r) . ' V' . ($yy + $alto) . ' Z';
                $s .= '<path class="' . $clases[$k] . '" d="' . $d . '"/>';
            } else {
                $s .= '<rect class="' . $clases[$k] . '" x="' . $x0 . '" y="' . $yy . '" width="' . round($bw, 1) . '" height="' . round($alto, 1) . '"/>';
            }
            $acum += $val;
        }
        $partes = [];
        foreach ($series as $k => $v) if ($v[$i] > 0) $partes[] = $nombres[$k] . ' ' . $v[$i];
        $tip = $fmt($etiquetas[$i]) . ' — ' . ($partes ? implode(' · ', $partes) : 'sin contactos');
        $s .= '<rect class="hit" x="' . round($ml + $slot * $i, 1) . '" y="' . $mt . '" width="' . round($slot, 1) . '" height="' . $ph . '" data-x="' . round($cx, 1) . '" data-tip="' . htmlspecialchars($tip) . '"/>';
    }
    $s .= '<line class="xhair" y1="' . $mt . '" y2="' . ($mt + $ph) . '" x1="0" x2="0"/>';
    return $s . '</svg>';
}

/** Mapa de calor día de la semana × hora. */
function tabla_calor(array $m, string $unidad, string $clase, array $diasSem): string {
    $max = 0;
    foreach ($m as $fila) $max = max($max, max($fila));
    $s = '<div class="tabla-scroll"><table class="calor ' . $clase . '"><thead><tr><th></th>';
    for ($hh = 0; $hh < 24; $hh++) $s .= '<th>' . ($hh % 3 === 0 ? $hh : '') . '</th>';
    $s .= '</tr></thead><tbody>';
    foreach ($m as $d => $fila) {
        $s .= '<tr><th>' . $diasSem[$d] . '</th>';
        foreach ($fila as $hh => $v) {
            $nivel = $v <= 0 || $max <= 0 ? 0 : max(1, (int)ceil($v / $max * 5));
            $s .= '<td class="n' . $nivel . '" title="' . $diasSem[$d] . ' ' . $hh . ':00 — ' . $v . ' ' . $unidad . '"></td>';
        }
        $s .= '</tr>';
    }
    return $s . '</tbody></table></div>';
}

function fila_barra(float $valor, float $max, string $clase = ''): string {
    $w = $max > 0 ? max(0, min(100, $valor / $max * 100)) : 0;
    return '<div class="barra ' . $clase . '"><span style="width:' . round($w, 1) . '%"></span></div>';
}

/** Tabla de una dimensión por sesión: sesiones, peso, contactos, conversión y rebote. */
function tabla_dim(array $datos, string $titulo, callable $h, callable $pct, int $limite = 8, ?callable $etq = null): string {
    $tot = array_sum(array_column($datos, 'ses'));
    $maxSes = $datos ? max(array_column($datos, 'ses')) : 0;
    $s = '<div class="tabla-scroll"><table><thead><tr><th>' . $h($titulo) . '</th><th>Sesiones</th><th></th><th>Contactos</th><th>Convierte</th><th>Rebote</th></tr></thead><tbody>';
    foreach (array_slice($datos, 0, $limite, true) as $k => $d) {
        $s .= '<tr><td>' . $h($etq ? $etq((string)$k) : $k) . '</td><td>' . $d['ses'] . ' <small>' . $pct($d['ses'], $tot, 0) . '</small></td>'
            . '<td class="w-barra">' . fila_barra((float)$d['ses'], (float)$maxSes) . '</td>'
            . '<td>' . ($d['cont'] ?? 0) . '</td><td>' . $pct($d['conv'] ?? 0, $d['ses']) . '</td><td>' . $pct($d['reb'] ?? 0, $d['ses'], 0) . '</td></tr>';
    }
    if (!$datos) $s .= '<tr><td colspan="6" class="vacio">Sin datos todavía.</td></tr>';
    elseif (count($datos) > $limite) $s .= '<tr><td colspan="6" class="mas">+ ' . (count($datos) - $limite) . ' más (ver CSV)</td></tr>';
    return $s . '</tbody></table></div>';
}

// ---------------- Hallazgos automáticos ----------------
$hallazgos = [];
$add = static function (string $tipo, string $texto) use (&$hallazgos): void { $hallazgos[] = [$tipo, $texto]; };
$muestraChica = $T['sesiones'] < 30;

if ($phpVulnerable) $add('Alerta', 'El servidor usa PHP ' . PHP_VERSION . ', vulnerable a «php2shell» (CVE-2026-109618/109619, ya explotada). Actualiza en cPanel → MultiPHP Manager a ' . $phpParche . ' o superior, o pídelo al hosting.');
if ($correoFallido > 0) $add('Alerta', $correoFallido . ' envío(s) del formulario no salieron porque falló el correo del servidor: esos clientes vieron un error. Revisa en cPanel que exista noreply@sipcons.com y el registro de correo.');
if (($R['errores_form']['límite por hora'] ?? 0) > 0) $add('Dato', ($R['errores_form']['límite por hora']) . ' envío(s) frenados por el límite de 5 por hora por persona (protección contra spam).');
if ($formIni >= 3 && $formOk === 0) $add('Alerta', $formIni . ' personas empezaron a llenar el formulario y ninguna lo envió en el periodo. Pruébalo tú mismo y revisa la sección «Formulario».');
if (!$porHoras && $dias >= 7 && $formOk === 0 && $formMalReal === 0 && $T['sesiones'] >= 20) $add('Dato', 'Ningún envío del formulario en el periodo. Los clientes están prefiriendo WhatsApp o teléfono.');

if ($T['sesiones'] > 0) {
    $mejor = null;
    foreach ($R['fuentes'] as $f => $d) {
        if ($d['ses'] >= 10) {
            $c = ($d['conv'] ?? 0) / $d['ses'];
            if ($mejor === null || $c > $mejor[1]) $mejor = [$f, $c, $d['ses']];
        }
    }
    if ($mejor && $mejor[1] > 0) $add('Dato', '«' . $mejor[0] . '» es la fuente que mejor convierte: ' . number_format($mejor[1] * 100, 1) . '% de sus ' . $mejor[2] . ' sesiones terminan en contacto (sitio: ' . number_format($conv, 1) . '%).');
    foreach ($R['fuentes'] as $f => $d) {
        if ($d['ses'] >= 20 && ($d['conv'] ?? 0) === 0) { $add('Alerta', '«' . $f . '» trae ' . $d['ses'] . ' sesiones y ningún contacto: revisa a qué página aterrizan o si conviene seguir invirtiendo ahí.'); break; }
    }
    $mov = $R['dispositivos']['Móvil'] ?? null; $esc = $R['dispositivos']['Escritorio'] ?? null;
    if ($mov && $mov['ses'] / $T['sesiones'] > 0.5) $add('Dato', number_format($mov['ses'] / $T['sesiones'] * 100, 0) . '% de las visitas llegan desde el celular: la versión móvil es la que más pesa.');
    if ($mov && $esc && $mov['ses'] >= 15 && $esc['ses'] >= 15) {
        $cm = ($mov['conv'] ?? 0) / $mov['ses']; $ce = ($esc['conv'] ?? 0) / $esc['ses'];
        if ($ce > 0 && $cm < $ce * 0.7) $add('Oportunidad', 'En móvil solo ' . number_format($cm * 100, 1) . '% contacta vs ' . number_format($ce * 100, 1) . '% en escritorio: hay margen para mejorar botones y velocidad en celular.');
    }
    $nuevos = $R['tipo_visitante']['Primera visita'] ?? null; $rec = $R['tipo_visitante']['Ya había venido'] ?? null;
    if ($nuevos && $rec && $rec['ses'] >= 10 && $nuevos['ses'] >= 10) {
        $cn = ($nuevos['conv'] ?? 0) / $nuevos['ses']; $cr = ($rec['conv'] ?? 0) / $rec['ses'];
        if ($cr > $cn * 1.5 && $cr > 0) $add('Dato', 'Quien regresa contacta ' . number_format($cr / max($cn, 0.0001), 1) . ' veces más que quien viene por primera vez (' . number_format($cr * 100, 1) . '% vs ' . number_format($cn * 100, 1) . '%): vale la pena hacer que vuelvan (redes, correo, remarketing).');
    }
    if ($contactos >= 5) {
        $hh = array_fill(0, 24, 0); $dd = array_fill(1, 7, 0);
        foreach ($R['heat_contactos'] as $d => $fila) foreach ($fila as $h0 => $v) { $hh[$h0] += $v; $dd[$d] += $v; }
        arsort($hh); arsort($dd);
        $hp = (int)array_key_first($hh); $dp = (int)array_key_first($dd);
        $add('Dato', 'Más contactos: ' . $diasSem[$dp] . ' y de ' . $hp . ':00 a ' . ($hp + 1) . ':00. Conviene tener a alguien atendiendo WhatsApp en ese horario.');
    }
    if ($contactos >= 5 && ($R['por_tipo']['whatsapp'] ?? 0) / $contactos > 0.5) $add('Dato', number_format(($R['por_tipo']['whatsapp'] ?? 0) / $contactos * 100, 0) . '% de los contactos es por WhatsApp: es el canal principal.');
    $sinCot = [];
    foreach ($R['productos'] as $slug => $d) if (($d['vistas'] ?? 0) >= 8 && ($d['cotizar'] ?? 0) === 0) $sinCot[] = $nombreProducto((string)$slug) . ' (' . $d['vistas'] . ' vistas)';
    if ($sinCot) $add('Oportunidad', 'Productos con visitas pero sin cotizaciones: ' . implode(', ', array_slice($sinCot, 0, 3)) . '. Revisa foto, ficha técnica y descripción.');
    $sinRes = [];
    foreach ($R['busquedas'] as $q => $d) if (($d['sin'] ?? 0) > 0) $sinRes[] = '«' . $q . '»';
    if ($sinRes) $add('Oportunidad', 'Buscaron y no encontraron: ' . implode(', ', array_slice($sinRes, 0, 4)) . '. Es demanda que hoy el catálogo no cubre.');
    if ($formIni >= 5 && $formOk / $formIni < 0.5) $add('Alerta', 'Abandono del formulario: ' . $formIni . ' personas empezaron a llenarlo y solo ' . $formOk . ' lo enviaron (' . number_format((1 - $formOk / $formIni) * 100, 0) . '% lo deja a medias). Considera pedir menos campos.');
    if ($T['sesiones'] >= 30 && $rebote > 60) $add('Alerta', 'Rebote alto (' . number_format($rebote, 0) . '%): más de la mitad se va sin interactuar. Revisa la portada y la velocidad de carga.');
    $bajo = null;
    foreach ($R['paginas'] as $p => $d) if (($d['n_sc'] ?? 0) >= 10 && ($d['vistas'] ?? 0) >= 15) { $sc = $d['sc'] / $d['n_sc']; if ($bajo === null || $sc < $bajo[1]) $bajo = [$p, $sc]; }
    if ($bajo && $bajo[1] < 40) $add('Oportunidad', 'En ' . $nombrePagina((string)$bajo[0]) . ' la gente solo baja hasta el ' . number_format($bajo[1], 0) . '% de la página: lo importante debería ir más arriba.');
    if ($R['e404']) $add('Alerta', 'Enlaces rotos: ' . $nf(array_sum($R['e404'])) . ' visitas cayeron en «Página no encontrada» (la más frecuente: ' . (string)array_key_first($R['e404']) . ').');
}
$orden = ['Alerta' => 0, 'Oportunidad' => 1, 'Dato' => 2];
usort($hallazgos, static fn($a, $b) => $orden[$a[0]] <=> $orden[$b[0]]);
$estilos = ['Dato' => 'dato', 'Oportunidad' => 'op', 'Alerta' => 'alerta'];

$secciones = ['resumen' => 'Resumen', 'vivo' => 'Ahora', 'contactos' => 'Contactos', 'formulario' => 'Formulario', 'tendencia' => 'Tendencia',
              'adquisicion' => 'Adquisición', 'paginas' => 'Páginas', 'productos' => 'Productos', 'catalogo' => 'Catálogo',
              'horarios' => 'Horarios', 'salud' => 'Salud técnica'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<title>Analítica · SIPCONS</title>
<style>
  :root{
    color-scheme:light;
    --plano:#f4f6fa; --sup:#fcfcfb; --tinta:#0b0b0b; --tinta2:#52514e; --tenue:#898781;
    --linea:#e1e0d9; --base:#c3c2b7; --borde:rgba(11,11,11,.10);
    --s1:#2a78d6; --s2:#eb6834; --s3:#1baf7a; --s4:#eda100; --neutro:#52514e;
    --bien:#006300; --mal:#d03b3b;
    --b100:#cde2fb; --b200:#9ec5f4; --b300:#5598e7; --b400:#256abf; --b500:#0d366b;
    --o100:#fbdccd; --o200:#f6b899; --o300:#eb6834; --o400:#b84a1f; --o500:#7a2f12;
    --navy:#0e1b3d;
    --e1:#86b6ef; --e2:#3987e5; --e3:#1c5cab; --e4:#0d366b;
  }
  @media (prefers-color-scheme:dark){
    :root{
      color-scheme:dark;
      --plano:#0d0d0d; --sup:#1a1a19; --tinta:#ffffff; --tinta2:#c3c2b7; --tenue:#898781;
      --linea:#2c2c2a; --base:#383835; --borde:rgba(255,255,255,.10);
      --s1:#3987e5; --s2:#d95926; --s3:#199e70; --s4:#c98500; --neutro:#c3c2b7;
      --bien:#0ca30c; --mal:#ec5b5b;
      --b100:#0d366b; --b200:#104281; --b300:#1c5cab; --b400:#3987e5; --b500:#86b6ef;
      --o100:#4a2410; --o200:#7a2f12; --o300:#b84a1f; --o400:#d95926; --o500:#f6b899;
      --navy:#0e1b3d;
      --e1:#184f95; --e2:#256abf; --e3:#3987e5; --e4:#6da7ec;
    }
  }
  *{box-sizing:border-box}
  html{scroll-behavior:smooth;scroll-padding-top:64px}
  body{margin:0;background:var(--plano);color:var(--tinta);font:15px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}
  .wrap{max-width:1180px;margin:0 auto;padding:24px 20px 64px}
  header.top{display:flex;flex-wrap:wrap;gap:12px 24px;align-items:flex-end;justify-content:space-between;margin-bottom:12px}
  h1{font-size:24px;margin:0} .sub{color:var(--tinta2);font-size:13px;margin:2px 0 0}
  .controles{display:flex;flex-direction:column;gap:8px;align-items:flex-end}
  .rango{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end}
  .rango a,.boton{padding:7px 14px;border-radius:999px;border:1px solid var(--borde);background:var(--sup);color:var(--tinta);text-decoration:none;font-size:13px;font-family:inherit;cursor:pointer;line-height:1.4}
  .rango a.act{background:var(--navy);color:#fff;border-color:var(--navy);font-weight:600}
  .rango a:focus-visible,summary:focus-visible,.boton:focus-visible,input:focus-visible{outline:2px solid var(--s1);outline-offset:2px}
  form.medida{display:flex;gap:6px;align-items:center;flex-wrap:wrap;justify-content:flex-end;font-size:13px;color:var(--tinta2)}
  form.medida input[type=date]{font:inherit;font-size:13px;padding:5px 8px;border:1px solid var(--borde);border-radius:8px;background:var(--sup);color:var(--tinta)}
  .interruptor{font-size:13px;color:var(--tinta2);text-decoration:none}
  .interruptor b{color:var(--tinta)}
  nav.secciones{position:sticky;top:0;z-index:5;background:var(--plano);border-bottom:1px solid var(--borde);margin:0 -20px 4px;padding:8px 20px;display:flex;gap:4px;overflow-x:auto;scrollbar-width:thin}
  nav.secciones a{flex:none;font-size:13px;color:var(--tinta2);text-decoration:none;padding:5px 10px;border-radius:999px}
  nav.secciones a:hover{background:var(--sup);color:var(--tinta)}
  h2{font-size:17px;margin:34px 0 4px} h2 + .nota{margin-top:0}
  .nota{color:var(--tinta2);font-size:13px;margin:0 0 12px}
  .panel{background:var(--sup);border:1px solid var(--borde);border-radius:14px;padding:18px 20px}
  .grid{display:grid;gap:16px}
  .g2{grid-template-columns:repeat(2,minmax(0,1fr))} .g3{grid-template-columns:repeat(3,minmax(0,1fr))}
  @media (max-width:900px){.g2,.g3{grid-template-columns:1fr}}
  .aviso{background:#fff7e0;color:#5a4300;border:1px solid #f0d78a;border-radius:10px;padding:10px 14px;font-size:13px;margin:12px 0}
  .aviso.critico{background:#fdeaea;color:#7a1414;border-color:#f1b5b5}
  @media (prefers-color-scheme:dark){.aviso{background:#2e2608;color:#f0d78a;border-color:#5a4a12}.aviso.critico{background:#3a1010;color:#f6b4b4;border-color:#6b2020}}
  /* KPIs */
  .kpis{display:grid;gap:14px;grid-template-columns:1.6fr repeat(3,1fr);margin-top:16px}
  @media (max-width:980px){.kpis{grid-template-columns:1fr 1fr}}
  .kpi{background:var(--sup);border:1px solid var(--borde);border-radius:14px;padding:16px 18px}
  .kpi .et{font-size:13px;color:var(--tinta2)} .kpi .val{font-size:30px;font-weight:650;line-height:1.15;margin:4px 0}
  .kpi.hero{grid-row:span 2} .kpi.hero .val{font-size:56px;line-height:1.05}
  .kpi small{color:var(--tenue)}
  .desglose{display:flex;flex-wrap:wrap;gap:6px 14px;margin-top:10px;font-size:13px;color:var(--tinta2)}
  .desglose b{color:var(--tinta)}
  .delta{font-size:12.5px;display:inline-block} .delta small{font-size:12px;opacity:.85}
  .delta.bien{color:var(--bien)} .delta.mal{color:var(--mal)} .delta.neutro{color:var(--tenue)}
  /* hallazgos */
  .hall{list-style:none;margin:0;padding:0;display:grid;gap:10px}
  .hall li{display:flex;gap:12px;align-items:flex-start;background:var(--sup);border:1px solid var(--borde);border-radius:12px;padding:12px 14px}
  .tag{flex:none;font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;padding:3px 9px;border-radius:999px;border:1px solid currentColor}
  .tag.dato{color:var(--s1)} .tag.op{color:var(--bien)} .tag.alerta{color:var(--mal)}
  .chip{display:inline-block;font-size:11px;font-weight:650;padding:1px 8px;border-radius:999px;background:var(--linea);color:var(--tinta2);vertical-align:1px}
  .chip.prueba{background:#fff1c2;color:#6a4d00} .chip.ok{background:#d9f2e3;color:#0b5a2a} .chip.mal{background:#fbdcdc;color:#8a1c1c}
  @media (prefers-color-scheme:dark){.chip.prueba{background:#4a3a00;color:#ffe08a}.chip.ok{background:#0f3d22;color:#9fe3b8}.chip.mal{background:#4a1515;color:#f6b4b4}}
  .canal{display:inline-flex;align-items:center;gap:6px;font-weight:600}
  .canal i{width:9px;height:9px;border-radius:50%;display:inline-block}
  /* en vivo */
  .vivo-num{display:flex;align-items:center;gap:10px;font-size:34px;font-weight:650}
  .pulso{width:12px;height:12px;border-radius:50%;background:var(--s3);box-shadow:0 0 0 0 rgba(27,175,122,.6);animation:pulso 2s infinite}
  .pulso.apagado{background:var(--base);animation:none}
  @keyframes pulso{70%{box-shadow:0 0 0 10px rgba(27,175,122,0)}100%{box-shadow:0 0 0 0 rgba(27,175,122,0)}}
  @media (prefers-reduced-motion:reduce){.pulso{animation:none}}
  .feed{list-style:none;margin:0;padding:0;font-size:13.5px}
  .feed li{display:grid;grid-template-columns:62px 1fr;gap:10px;padding:7px 0;border-bottom:1px solid var(--linea)}
  .feed li:last-child{border-bottom:0}
  .feed time{color:var(--tenue);font-variant-numeric:tabular-nums}
  .feed small{color:var(--tenue)}
  /* gráficas */
  .chart{width:100%;height:auto;display:block}
  .chart .gl{stroke:var(--linea);stroke-width:1} .chart .tick{fill:var(--tenue);font-size:13.5px}
  .chart .area{fill:var(--neutro);opacity:.10} .chart .linea{fill:none;stroke:var(--neutro);stroke-width:2;stroke-linejoin:round;stroke-linecap:round}
  .chart .punto{fill:var(--neutro);stroke:var(--sup);stroke-width:2}
  .chart .c-formulario{fill:var(--s1)} .chart .c-telefono{fill:var(--s2)} .chart .c-whatsapp{fill:var(--s3)} .chart .c-correo{fill:var(--s4)}
  .chart .xhair{stroke:var(--base);stroke-width:1;visibility:hidden} .chart .hit{fill:transparent}
  .leyenda{display:flex;flex-wrap:wrap;gap:6px 18px;font-size:13px;color:var(--tinta2);margin-bottom:8px}
  .leyenda i{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:6px;vertical-align:baseline}
  #tip{position:fixed;z-index:9;pointer-events:none;background:var(--navy);color:#fff;font-size:12.5px;padding:6px 10px;border-radius:8px;display:none;max-width:280px}
  details{margin-top:10px;font-size:13px} summary{cursor:pointer;color:var(--tinta2)}
  /* tablas */
  .tabla-scroll{overflow-x:auto}
  table{width:100%;border-collapse:collapse;font-size:13.5px}
  th,td{padding:8px 10px;text-align:right;border-bottom:1px solid var(--linea);white-space:nowrap}
  th:first-child,td:first-child{text-align:left;white-space:normal;word-break:break-word}
  td.izq,th.izq{text-align:left;white-space:normal}
  th{font-size:12px;color:var(--tinta2);font-weight:600;background:transparent}
  td small{color:var(--tenue)}
  td.vacio{text-align:center;color:var(--tenue);padding:18px}
  td.mas{text-align:left;color:var(--tenue);font-size:12.5px}
  td.w-barra{width:110px}
  .barra{height:8px;background:var(--linea);border-radius:4px;min-width:80px;overflow:hidden}
  .barra span{display:block;height:100%;background:var(--s1);border-radius:4px}
  .barra.o span{background:var(--s2)}
  /* embudo */
  .embudo{display:grid;gap:10px}
  .paso{display:grid;grid-template-columns:220px 1fr 240px;gap:12px;align-items:center;font-size:13.5px}
  @media (max-width:700px){.paso{grid-template-columns:1fr}}
  .paso .bar{height:26px;border-radius:0 6px 6px 0;min-width:4px}
  .paso:nth-child(1) .bar{background:var(--e1)} .paso:nth-child(2) .bar{background:var(--e2)}
  .paso:nth-child(3) .bar{background:var(--e3)} .paso:nth-child(4) .bar{background:var(--e4)}
  .paso .cant{text-align:right;color:var(--tinta2)}
  /* calor */
  table.calor{table-layout:fixed;min-width:640px}
  .calor th{padding:2px 0;text-align:center;font-size:10.5px;color:var(--tenue);border:0;white-space:nowrap}
  .calor th:first-child{width:38px;text-align:left}
  .calor td{padding:0;height:24px;border:2px solid var(--sup);border-radius:4px}
  .calor td.n0{background:var(--linea)}
  .calor.az td.n1{background:var(--b100)} .calor.az td.n2{background:var(--b200)} .calor.az td.n3{background:var(--b300)} .calor.az td.n4{background:var(--b400)} .calor.az td.n5{background:var(--b500)}
  .calor.na td.n1{background:var(--o100)} .calor.na td.n2{background:var(--o200)} .calor.na td.n3{background:var(--o300)} .calor.na td.n4{background:var(--o400)} .calor.na td.n5{background:var(--o500)}
  /* estado técnico */
  dl.estado{display:grid;grid-template-columns:max-content 1fr;gap:8px 18px;margin:0;font-size:13.5px}
  dl.estado dt{color:var(--tinta2)} dl.estado dd{margin:0}
  .pie{margin-top:40px;color:var(--tenue);font-size:12.5px;max-width:82ch}
  .pie a{color:var(--s1)}
  @media print{
    nav.secciones,.controles,details,#tip{display:none!important}
    body{background:#fff} .panel,.kpi,.hall li{break-inside:avoid}
  }
</style>
</head>
<body>
<div class="wrap">

<header class="top" id="resumen">
  <div>
    <h1>Analítica de sipcons.com</h1>
    <p class="sub"><?= $porHoras ? 'Hoy, ' . $h($fechaCorta($desde)) : 'Del ' . $h($fechaCorta($desde)) . ' al ' . $h($fechaCorta($ultimoDia0)) . ' · ' . $dias . ' días' ?> · comparado con <?= $h($etPeriodo) ?> · hora de Tijuana · actualizado a las <?= date('H:i') ?></p>
  </div>
  <div class="controles">
    <nav class="rango" aria-label="Periodo">
      <?php foreach ([1 => 'Hoy', 7 => '7 días', 30 => '30 días', 90 => '90 días', 365 => '12 meses'] as $d => $et): ?>
        <a href="<?= $url(['dias' => $d, 'desde' => null, 'hasta' => null]) ?>" class="<?= !$aMedida && $d === $dias ? 'act' : '' ?>"><?= $et ?></a>
      <?php endforeach; ?>
      <a href="<?= $url() ?>" title="Volver a cargar los datos">↻ Actualizar</a>
      <a href="<?= $url(['export' => 'csv']) ?>">Exportar CSV</a>
    </nav>
    <form class="medida" method="get" action="">
      <input type="hidden" name="token" value="<?= $h($token) ?>">
      <?php if (!$conPruebas): ?><input type="hidden" name="pruebas" value="0"><?php endif; ?>
      <label>Del <input type="date" name="desde" value="<?= $h(date('Y-m-d', $desde)) ?>" max="<?= $h($hoyClave) ?>"></label>
      <label>al <input type="date" name="hasta" value="<?= $h(date('Y-m-d', $ultimoDia0)) ?>" max="<?= $h($hoyClave) ?>"></label>
      <button type="submit" class="boton<?= $aMedida ? ' act' : '' ?>">Ver rango</button>
    </form>
    <a class="interruptor" href="<?= $url(['pruebas' => $conPruebas ? '0' : null]) ?>">
      Pruebas internas: <b><?= $conPruebas ? 'incluidas' : 'excluidas' ?></b> (<?= $nf($R['pruebas']) ?> en el periodo) · <?= $conPruebas ? 'excluirlas' : 'incluirlas' ?>
    </a>
  </div>
</header>

<nav class="secciones" aria-label="Secciones">
  <?php foreach ($secciones as $id => $et): ?><a href="#<?= $id ?>"><?= $h($et) ?></a><?php endforeach; ?>
</nav>

<?php if ($phpVulnerable): ?>
<div class="aviso critico"><strong>Seguridad: PHP <?= $h(PHP_VERSION) ?> es vulnerable a «php2shell» (CVE-2026-109618 / CVE-2026-109619).</strong> Permite tomar control del servidor con una sola petición y ya se está explotando. Actualiza en cPanel → MultiPHP Manager a <?= $h($phpParche) ?> o superior (o pídeselo al hosting).</div>
<?php endif; ?>
<?php if ($correoFallido > 0): ?>
<div class="aviso critico"><strong><?= $correoFallido ?> mensaje(s) del formulario no se pudieron enviar por correo en este periodo.</strong> El cliente vio un error y el mensaje no llegó a info@sipcons.com. Revisa en cPanel la cuenta noreply@sipcons.com y el «Rastrear entrega» del correo.</div>
<?php endif; ?>
<?php if (!$dirEscribible || $eventosRango === 0): ?>
<div class="aviso">
  <strong><?= !$dirEscribible ? 'El servidor no puede escribir en la carpeta inc/.' : 'No hay ningún evento registrado en este periodo.' ?></strong>
  <?php if (!$dirEscribible): ?>En cPanel → Administrador de archivos, ponle permisos 755 (o 775) a la carpeta <code>inc</code> y recarga.
  <?php else: ?>Revisa: (1) que se haya subido la última versión del sitio; (2) que no haya un bloqueador de anuncios o modo privado estricto; (3) que la carpeta <code>inc</code> tenga permisos de escritura. Estado técnico al final de la página.
  <?php endif; ?>
</div>
<?php endif; ?>
<?php if ($muestraChica && $eventosRango > 0): ?>
<div class="aviso"><strong>Muestra pequeña (<?= $T['sesiones'] ?> sesiones).</strong> Con pocas visitas los porcentajes cambian mucho de un día a otro: úsalos como tendencia, no como conclusión.</div>
<?php endif; ?>

<section class="kpis" aria-label="Resumen">
  <div class="kpi hero">
    <div class="et">Contactos en el periodo</div>
    <div class="val"><?= $nf($contactos) ?></div>
    <?= $delta($contactos, $contactosPrev) ?>
    <div class="desglose">
      <?php foreach ($nombresCanal as $k => $nombre): ?><span><b><?= $nf($R['por_tipo'][$k] ?? 0) ?></b> <?= $h($nombre) ?></span><?php endforeach; ?>
    </div>
    <div class="desglose"><span>Hoy: <b><?= $contactosHoy ?></b> contactos · <b><?= (int)($hoyDatos['vistas'] ?? 0) ?></b> vistas</span></div>
    <div class="desglose"><span>Último contacto: <b><?= $h($hace(max(array_map(static fn($c) => (int)($R['ultimo_por_tipo'][$c] ?? 0), SIPCONS_CONTACTOS)) ?: null)) ?></b></span><span>Último formulario: <b><?= $h($hace(isset($R['ultimo_por_tipo']['formulario']) ? (int)$R['ultimo_por_tipo']['formulario'] : null)) ?></b></span></div>
  </div>
  <div class="kpi"><div class="et">Visitantes únicos</div><div class="val"><?= $nf($T['visitantes']) ?></div><?= $delta($T['visitantes'], $TP['visitantes']) ?><div><small><?= $nf($T['visitantes_nuevos']) ?> nuevos</small></div></div>
  <div class="kpi"><div class="et">Sesiones</div><div class="val"><?= $nf($T['sesiones']) ?></div><?= $delta($T['sesiones'], $TP['sesiones']) ?><div><small><?= number_format($pagsPorSesion, 1) ?> páginas por sesión</small></div></div>
  <div class="kpi"><div class="et">Vistas de página</div><div class="val"><?= $nf($T['vistas']) ?></div><?= $delta($T['vistas'], $TP['vistas']) ?></div>
  <div class="kpi"><div class="et">Sesiones que contactan</div><div class="val"><?= number_format($conv, 1) ?>%</div><?= $delta($conv, $convPrev) ?><div><small><?= $nf($T['con_contacto']) ?> de <?= $nf($T['sesiones']) ?></small></div></div>
  <div class="kpi"><div class="et">Tiempo activo por sesión</div><div class="val"><?= $h($dur((float)$msProm)) ?></div><small>solo cuenta pestañas visibles</small></div>
  <div class="kpi"><div class="et">Rebote</div><div class="val"><?= number_format($rebote, 0) ?>%</div><?= $delta($rebote, $rebotePrev, false) ?><div><small>1 página, sin interactuar, &lt; 15 s</small></div></div>
</section>

<h2>Hallazgos</h2>
<p class="nota">Lecturas automáticas del periodo, primero lo urgente. Si no hay suficiente muestra, no aparecen.</p>
<?php if ($hallazgos): ?>
<ul class="hall">
  <?php foreach ($hallazgos as [$tipo, $texto]): ?>
  <li><span class="tag <?= $estilos[$tipo] ?>"><?= $h($tipo) ?></span><span><?= $h($texto) ?></span></li>
  <?php endforeach; ?>
</ul>
<?php else: ?>
<div class="panel"><span class="vacio">Todavía no hay suficientes datos para sacar conclusiones.</span></div>
<?php endif; ?>

<h2 id="vivo">Ahora mismo</h2>
<p class="nota">Sesiones con actividad en los últimos 30 minutos y lo último que pasó en el sitio.</p>
<div class="grid g2">
  <div class="panel">
    <?php $vivos = $V['en_vivo']; ?>
    <div class="vivo-num"><span class="pulso<?= $vivos ? '' : ' apagado' ?>"></span><?= count($vivos) ?> <small style="font-size:14px;font-weight:400;color:var(--tinta2)">visitante<?= count($vivos) === 1 ? '' : 's' ?> activo<?= count($vivos) === 1 ? '' : 's' ?></small></div>
    <div class="tabla-scroll"><table><thead><tr><th>Viendo</th><th>Llegó por</th><th>Dispositivo</th><th>Páginas</th><th>Últ. actividad</th></tr></thead><tbody>
    <?php foreach (array_slice($vivos, 0, 12) as $vv): ?>
      <tr><td><?= $h($nombrePagina((string)$vv['ultima'])) ?><?= $vv['contactos'] > 0 ? ' <span class="chip ok">contactó</span>' : '' ?></td><td><?= $h($vv['fuente']) ?></td><td><?= $h($vv['d']) ?></td><td><?= (int)$vv['vistas'] ?></td><td><?= $h($hace((int)$vv['fin'])) ?></td></tr>
    <?php endforeach; if (!$vivos): ?><tr><td colspan="5" class="vacio">Nadie en este momento.</td></tr><?php endif; ?>
    </tbody></table></div>
  </div>
  <div class="panel">
    <strong>Últimos movimientos</strong>
    <ul class="feed">
    <?php foreach (array_slice($R['ultimos_eventos'], 0, 14) as $ev):
        $det = $ev['e'] === 'busqueda' ? '«' . $ev['q'] . '»' : ($ev['pr'] !== '' ? $nombreProducto($ev['pr']) : ($ev['lg'] !== '' ? $ev['lg'] : '')); ?>
      <li><time><?= $h(date('j', $ev['ts']) === date('j') ? date('H:i', $ev['ts']) : $fechaCorta($ev['ts'])) ?></time>
        <span><b><?= $h($nombresEvento[$ev['e']] ?? $ev['e']) ?></b> · <?= $h($nombrePagina($ev['p'])) ?><?= $det !== '' ? ' <small>· ' . $h($det) . '</small>' : '' ?><?= $ev['x'] ? ' <span class="chip prueba">prueba</span>' : '' ?></span></li>
    <?php endforeach; if (!$R['ultimos_eventos']): ?><li><span></span><span class="vacio">Sin actividad en el periodo.</span></li><?php endif; ?>
    </ul>
  </div>
</div>

<h2 id="contactos">Contactos recientes</h2>
<p class="nota">Cada contacto con el contexto de su visita: por dónde llegó, a qué página entró, cuántas páginas vio y cuánto tardó en decidirse. Es anónimo: el detalle de quién escribió está en el correo o en WhatsApp.</p>
<div class="panel tabla-scroll">
  <table><thead><tr><th>Cuándo</th><th class="izq">Canal</th><th class="izq">Desde</th><th class="izq">Llegó por</th><th class="izq">Entró en</th><th>Páginas antes</th><th>Tardó</th><th class="izq">Dispositivo</th></tr></thead><tbody>
  <?php $colorCanal = ['formulario' => '--s1', 'telefono' => '--s2', 'whatsapp' => '--s3', 'correo' => '--s4'];
  foreach (array_slice($R['recientes'], 0, 30) as $c):
      $desdeTxt = $c['pr'] !== '' ? $nombreProducto($c['pr']) : $nombrePagina($c['p']);
      $donde = $c['e'] === 'formulario' ? ($c['i'] !== '' ? 'Interés: ' . $c['i'] : '') : $c['lg']; ?>
    <tr>
      <td><?= $h($fechaHora((int)$c['ts'])) ?></td>
      <td class="izq"><span class="canal"><i style="background:var(<?= $colorCanal[$c['e']] ?? '--neutro' ?>)"></i><?= $h($nombresCanal[$c['e']] ?? $c['e']) ?></span><?= $c['x'] ? ' <span class="chip prueba">prueba</span>' : '' ?></td>
      <td class="izq"><?= $h($desdeTxt) ?><?= $donde !== '' ? '<br><small>' . $h($donde) . '</small>' : '' ?></td>
      <td class="izq"><?= $h($c['fuente'] ?? '—') ?><?= $c['nuevo'] === null ? '' : '<br><small>' . ($c['nuevo'] ? 'primera visita' : 'ya había venido') . '</small>' ?></td>
      <td class="izq"><?= $c['landing'] !== null ? $h($nombrePagina((string)$c['landing'])) : '—' ?></td>
      <td><?= $c['fuente'] !== null ? (int)$c['vistas'] : '—' ?></td>
      <td><?= $c['seg'] !== null ? $h($dur($c['seg'] * 1000.0)) : '—' ?></td>
      <td class="izq"><?= $h($c['d'] !== '' ? $c['d'] : '—') ?></td>
    </tr>
  <?php endforeach; if (!$R['recientes']): ?><tr><td colspan="8" class="vacio">Sin contactos en el periodo.</td></tr><?php endif; ?>
  </tbody></table>
  <?php if (count($R['recientes']) > 30): ?><p class="nota" style="margin:10px 0 0">Se muestran los 30 más recientes; el resto está en el CSV.</p><?php endif; ?>
</div>

<div class="grid g2" style="margin-top:16px">
  <div class="panel"><strong>Desde qué botón contactan</strong>
    <p class="nota">Lugar de la página donde estaba el botón (flotante, ficha de producto, menú, pie…).</p>
    <div class="tabla-scroll"><table><thead><tr><th>Canal · lugar</th><th>Contactos</th><th></th></tr></thead><tbody>
    <?php
    $filasLugar = [];
    foreach ($R['lugares'] as $canal => $lugs) foreach ($lugs as $lg => $n) $filasLugar[($nombresCanal[$canal] ?? $canal) . ' · ' . $lg] = $n;
    arsort($filasLugar);
    $maxL = $filasLugar ? max($filasLugar) : 0;
    foreach (array_slice($filasLugar, 0, 14, true) as $k => $n): ?>
      <tr><td><?= $h($k) ?></td><td><?= $n ?></td><td class="w-barra"><?= fila_barra((float)$n, (float)$maxL, 'o') ?></td></tr>
    <?php endforeach; if (!$filasLugar): ?><tr><td colspan="3" class="vacio">Sin datos todavía.</td></tr><?php endif; ?>
    </tbody></table></div></div>
  <div class="panel"><strong>Productos por los que contactan</strong>
    <p class="nota">Contactos hechos desde la ficha o la tarjeta de un producto.</p>
    <div class="tabla-scroll"><table><thead><tr><th>Producto</th><th>Contactos</th><th></th></tr></thead><tbody>
    <?php
    $porCot = array_filter($R['productos'], static fn($d) => ($d['cotizar'] ?? 0) > 0);
    uasort($porCot, static fn($a, $b) => ($b['cotizar'] ?? 0) <=> ($a['cotizar'] ?? 0));
    $maxC = $porCot ? max(array_column($porCot, 'cotizar')) : 0;
    foreach (array_slice($porCot, 0, 12, true) as $slug => $d): ?>
      <tr><td><?= $h($nombreProducto((string)$slug)) ?></td><td><?= (int)$d['cotizar'] ?></td><td class="w-barra"><?= fila_barra((float)$d['cotizar'], (float)$maxC) ?></td></tr>
    <?php endforeach; if (!$porCot): ?><tr><td colspan="3" class="vacio">Sin datos todavía.</td></tr><?php endif; ?>
    </tbody></table></div></div>
</div>

<h2 id="formulario">Formulario de contacto</h2>
<p class="nota">Cada intento de envío queda registrado, también los de navegadores con «No rastrear». Los que no llegaron aparecen con su motivo.</p>
<div class="grid g3">
  <div class="kpi"><div class="et">Empezaron a llenarlo</div><div class="val"><?= $nf($formIni) ?></div><small>tocaron algún campo</small></div>
  <div class="kpi"><div class="et">Enviados con éxito</div><div class="val"><?= $nf($formOk) ?></div><?= $delta($formOk, (int)($P['por_tipo']['formulario'] ?? 0)) ?><div><small>último: <?= $h($hace(isset($R['ultimo_por_tipo']['formulario']) ? (int)$R['ultimo_por_tipo']['formulario'] : null)) ?></small></div></div>
  <div class="kpi"><div class="et">No llegaron</div><div class="val" style="color:<?= $formMalReal > 0 ? 'var(--mal)' : 'inherit' ?>"><?= $nf($formMalReal) ?></div><small>de personas reales · <?= $nf($formMal - $formMalReal) ?> de bots u otros sitios</small></div>
</div>
<div class="grid g2" style="margin-top:16px">
  <div class="panel"><strong>Por qué no se enviaron</strong>
    <div class="tabla-scroll"><table><thead><tr><th>Motivo</th><th>Veces</th></tr></thead><tbody>
    <?php $explica = ['faltan campos' => 'el visitante dejó campos vacíos', 'correo inválido' => 'correo mal escrito', 'teléfono inválido' => 'teléfono mal escrito',
                      'límite por hora' => 'más de 5 envíos en una hora', 'falló el envío del correo' => 'problema del servidor de correo: revisar',
                      'bot (honeypot)' => 'robot detectado, descartado', 'otro sitio' => 'intento desde fuera de sipcons.com, bloqueado'];
    foreach ($R['errores_form'] as $mot => $n): ?>
      <tr><td><?= $h($mot) ?><?= isset($explica[$mot]) ? '<br><small>' . $h($explica[$mot]) . '</small>' : '' ?></td><td><?= $n ?></td></tr>
    <?php endforeach; if (!$R['errores_form']): ?><tr><td colspan="2" class="vacio">Ningún envío fallido en el periodo.</td></tr><?php endif; ?>
    </tbody></table></div>
    <p class="nota" style="margin:12px 0 0">Abandono: <b><?= $formIni > 0 ? $pct(max(0, $formIni - $formOk), $formIni, 0) : '—' ?></b> de quienes lo empiezan no lo envía. Envíos registrados en la última hora: <?= $enviosUltimaHora ?>.</p>
  </div>
  <div class="panel"><strong>Qué les interesa</strong>
    <div class="tabla-scroll"><table><thead><tr><th>Interés declarado</th><th>Envíos</th><th></th></tr></thead><tbody>
    <?php $maxI = $R['intereses'] ? max($R['intereses']) : 0;
    foreach ($R['intereses'] as $i => $n): ?><tr><td><?= $h($i) ?></td><td><?= $n ?></td><td class="w-barra"><?= fila_barra((float)$n, (float)$maxI) ?></td></tr><?php endforeach; if (!$R['intereses']): ?><tr><td colspan="3" class="vacio">Sin datos todavía.</td></tr><?php endif; ?>
    </tbody></table></div></div>
</div>

<h2 id="tendencia">Tendencia</h2>
<div class="grid g2">
  <div class="panel">
    <strong>Vistas de página por <?= $porHoras ? 'hora' : 'día' ?></strong>
    <p class="nota">Cada carga de una página cuenta como una vista.</p>
    <?= svg_linea($etiquetas, $serieVistas, 'vistas', 'vistas', $fmtEtiqueta) ?>
    <details><summary>Ver tabla</summary><div class="tabla-scroll"><table><thead><tr><th><?= $porHoras ? 'Hora' : 'Día' ?></th><th>Vistas</th></tr></thead><tbody>
      <?php foreach (array_reverse($etiquetas, true) as $i => $f): ?><tr><td><?= $h($fmtEtiqueta($f)) ?></td><td><?= $serieVistas[$i] ?></td></tr><?php endforeach; ?>
    </tbody></table></div></details>
  </div>
  <div class="panel">
    <strong>Contactos por <?= $porHoras ? 'hora' : 'día' ?></strong>
    <div class="leyenda">
      <?php foreach (['formulario' => '--s1', 'telefono' => '--s2', 'whatsapp' => '--s3', 'correo' => '--s4'] as $k => $var): ?>
        <span><i style="background:var(<?= $var ?>)"></i><?= $h($nombresCanal[$k]) ?></span>
      <?php endforeach; ?>
    </div>
    <?= svg_apiladas($etiquetas, $serieContactos, $nombresCanal, ['formulario' => 'c-formulario', 'telefono' => 'c-telefono', 'whatsapp' => 'c-whatsapp', 'correo' => 'c-correo'], 'contactos', $fmtEtiqueta) ?>
    <details><summary>Ver tabla</summary><div class="tabla-scroll"><table><thead><tr><th><?= $porHoras ? 'Hora' : 'Día' ?></th><?php foreach ($nombresCanal as $n): ?><th><?= $h($n) ?></th><?php endforeach; ?></tr></thead><tbody>
      <?php foreach (array_reverse($etiquetas, true) as $i => $f): ?><tr><td><?= $h($fmtEtiqueta($f)) ?></td><?php foreach (array_keys($nombresCanal) as $k): ?><td><?= $serieContactos[$k][$i] ?? 0 ?></td><?php endforeach; ?></tr><?php endforeach; ?>
    </tbody></table></div></details>
  </div>
</div>

<h2>Recorrido: de la visita al contacto</h2>
<p class="nota">Cuántas sesiones llegan a cada etapa. Donde la barra se acorta mucho es donde se pierde gente.</p>
<div class="panel embudo">
  <?php
  $E = $R['embudo'];
  $pasos = [
      ['Sesiones', $E['sesiones']],
      ['Vieron productos', $E['productos']],
      ['Interactuaron (filtro, ficha, galería…)', $E['interaccion']],
      ['Contactaron', $E['contacto']],
  ];
  foreach ($pasos as $i => [$nombre, $cant]): ?>
    <div class="paso">
      <span><?= $h($nombre) ?></span>
      <div><div class="bar" style="width:<?= $E['sesiones'] > 0 ? max(0.5, $cant / $E['sesiones'] * 100) : 0 ?>%"></div></div>
      <span class="cant"><?= $nf($cant) ?> · <?= $pct($cant, $E['sesiones'], 0) ?><?= $i > 0 && $pasos[$i - 1][1] > 0 ? ' <small>(' . $pct($cant, $pasos[$i - 1][1], 0) . ' de la etapa previa)</small>' : '' ?></span>
    </div>
  <?php endforeach; ?>
</div>

<h2 id="adquisicion">De dónde vienen</h2>
<p class="nota">La fuente se toma de la primera página de cada sesión. «Contactos» = contactos hechos en esas sesiones; «Convierte» = % de sesiones con al menos un contacto.</p>
<div class="grid g2">
  <div class="panel"><strong>Por canal</strong><?= tabla_dim($R['canales'], 'Canal', $h, $pct, 10) ?></div>
  <div class="panel"><strong>Visitante nuevo o que regresa</strong><?= tabla_dim($R['tipo_visitante'], 'Tipo', $h, $pct) ?>
    <p class="nota" style="margin:12px 0 0">Profundidad: cuántas páginas ven por sesión y cuánto convierte cada grupo.</p>
    <?= tabla_dim($R['profundidad'], 'Páginas vistas', $h, $pct, 6, static fn(string $k): string => $k === '1' ? '1 página' : $k . ' páginas') ?></div>
</div>
<div class="panel" style="margin-top:16px"><strong>Fuente detallada</strong><?= tabla_dim($R['fuentes'], 'Fuente', $h, $pct, 15) ?></div>

<h2>Con qué entran</h2>
<div class="grid g3">
  <div class="panel"><strong>Dispositivo</strong><?= tabla_dim($R['dispositivos'], 'Tipo', $h, $pct) ?></div>
  <div class="panel"><strong>Navegador</strong><?= tabla_dim($R['navegadores'], 'Navegador', $h, $pct) ?></div>
  <div class="panel"><strong>Sistema</strong><?= tabla_dim($R['sistemas'], 'Sistema', $h, $pct) ?></div>
</div>

<h2 id="paginas">Páginas</h2>
<p class="nota">Tiempo y scroll promedio por visita. «Salidas» = % de visitas en que fue la última página de la sesión. «Contactos» = contactos hechos estando en esa página.</p>
<div class="panel tabla-scroll">
  <table><thead><tr><th>Página</th><th>Vistas</th><th>Visitas únicas</th><th>Tiempo prom.</th><th>Scroll prom.</th><th>Salidas</th><th>Contactos</th><th>Contacto por visita</th></tr></thead><tbody>
  <?php foreach (array_slice($R['paginas'], 0, 25, true) as $p => $d): $v = $d['visitas'] ?? 0; ?>
    <tr>
      <td><?= $h($nombrePagina((string)$p)) ?></td>
      <td><?= $nf($d['vistas'] ?? 0) ?></td><td><?= $nf($v) ?></td>
      <td><?= $v > 0 ? $h($dur(($d['ms'] ?? 0) / $v)) : '—' ?></td>
      <td><?= ($d['n_sc'] ?? 0) > 0 ? number_format($d['sc'] / $d['n_sc'], 0) . '%' : '—' ?></td>
      <td><?= $pct($d['salidas'] ?? 0, $v, 0) ?></td>
      <td><?= $nf($d['contactos'] ?? 0) ?></td>
      <td><?= $pct($d['contactos'] ?? 0, $v) ?></td>
    </tr>
  <?php endforeach; if (!$R['paginas']): ?><tr><td colspan="8" class="vacio">Sin datos todavía.</td></tr><?php endif; ?>
  </tbody></table>
</div>

<h2>Páginas de entrada</h2>
<p class="nota">Primera página que ve cada visitante y qué tan bien convierte. Sirve para decidir a dónde mandar el tráfico de anuncios y redes.</p>
<div class="panel"><?= tabla_dim($R['landings'], 'Entrada', $h, $pct, 12, $nombrePagina) ?></div>

<h2 id="productos">Productos: interés vs. acción</h2>
<p class="nota">Vistas de la ficha contra contactos para cotizar (WhatsApp, llamada o correo estando en el producto). Los marcados como <span class="chip mal">revisar</span> tienen visitas y ningún contacto.</p>
<div class="panel tabla-scroll">
  <table><thead><tr><th>Producto</th><th>Vistas de ficha</th><th>Cotizaciones</th><th>Cotiza</th><th>Ficha PDF</th><th>Galería</th></tr></thead><tbody>
  <?php foreach (array_slice($R['productos'], 0, 25, true) as $slug => $d): $revisar = ($d['vistas'] ?? 0) >= 8 && ($d['cotizar'] ?? 0) === 0; ?>
    <tr><td><?= $h($nombreProducto((string)$slug)) ?><?= $revisar ? ' <span class="chip mal">revisar</span>' : '' ?></td><td><?= $nf($d['vistas'] ?? 0) ?></td><td><?= $nf($d['cotizar'] ?? 0) ?></td><td><?= $pct($d['cotizar'] ?? 0, $d['vistas'] ?? 0, 0) ?></td><td><?= $nf($d['pdf'] ?? 0) ?></td><td><?= $nf($d['galeria'] ?? 0) ?></td></tr>
  <?php endforeach; if (!$R['productos']): ?><tr><td colspan="6" class="vacio">Sin datos todavía.</td></tr><?php endif; ?>
  </tbody></table>
  <?php if ($R['destacados']): ?>
    <p class="nota" style="margin-top:14px">Clics desde el carrusel de la portada: <?= $h(implode(' · ', array_map(fn($s, $n) => $nombreProducto((string)$s) . ' (' . $n . ')', array_keys(array_slice($R['destacados'], 0, 6, true)), array_slice($R['destacados'], 0, 6, true)))) ?></p>
  <?php endif; ?>
</div>

<h2 id="catalogo">Catálogo: qué buscan</h2>
<div class="grid g2">
  <div class="panel"><strong>Filtros más usados</strong>
    <div class="tabla-scroll"><table><thead><tr><th>Filtro</th><th>Veces</th></tr></thead><tbody>
    <?php foreach (array_slice($R['filtros'], 0, 12, true) as $f => $n): ?><tr><td><?= $h($f) ?></td><td><?= $n ?></td></tr><?php endforeach; if (!$R['filtros']): ?><tr><td colspan="2" class="vacio">Sin datos todavía.</td></tr><?php endif; ?>
    </tbody></table></div></div>
  <div class="panel"><strong>Búsquedas de texto</strong>
    <p class="nota">Las que no tuvieron resultados son productos que buscan y hoy no encuentran.</p>
    <div class="tabla-scroll"><table><thead><tr><th>Búsqueda</th><th>Veces</th><th>Sin resultados</th></tr></thead><tbody>
    <?php foreach (array_slice($R['busquedas'], 0, 15, true) as $q => $d): ?><tr><td><?= $h($q) ?><?= ($d['sin'] ?? 0) > 0 ? ' <span class="chip mal">sin resultados</span>' : '' ?></td><td><?= $d['n'] ?></td><td><?= $d['sin'] ?? 0 ?></td></tr><?php endforeach; if (!$R['busquedas']): ?><tr><td colspan="3" class="vacio">Sin datos todavía.</td></tr><?php endif; ?>
    </tbody></table></div></div>
</div>

<h2 id="horarios">Horarios</h2>
<p class="nota">Cuándo entran y cuándo contactan, por día de la semana y hora (Tijuana). Color más intenso = más actividad. Útil para decidir turnos de atención y horarios de publicaciones.</p>
<div class="grid">
  <div class="panel"><strong>Vistas de página</strong><?= tabla_calor($R['heat_vistas'], 'vistas', 'az', $diasSem) ?></div>
  <div class="panel"><strong>Contactos</strong><?= tabla_calor($R['heat_contactos'], 'contactos', 'na', $diasSem) ?></div>
</div>

<h2 id="salud">Salud técnica</h2>
<div class="grid g2">
  <div class="panel"><strong>Enlaces rotos (visitas a «Página no encontrada»)</strong>
    <div class="tabla-scroll"><table><thead><tr><th>Dirección buscada</th><th>Veces</th></tr></thead><tbody>
    <?php foreach (array_slice($R['e404'], 0, 10, true) as $p => $n): ?><tr><td><?= $h($p) ?></td><td><?= $n ?></td></tr><?php endforeach; if (!$R['e404']): ?><tr><td colspan="2" class="vacio">Ninguno en el periodo.</td></tr><?php endif; ?>
    </tbody></table></div></div>
  <div class="panel"><strong>Salidas a sitios externos</strong>
    <div class="tabla-scroll"><table><thead><tr><th>Destino</th><th>Clics</th></tr></thead><tbody>
    <?php foreach (array_slice($R['salientes'], 0, 10, true) as $d => $n): ?><tr><td><?= $h($d) ?></td><td><?= $n ?></td></tr><?php endforeach; if (!$R['salientes']): ?><tr><td colspan="2" class="vacio">Sin datos todavía.</td></tr><?php endif; ?>
    </tbody></table></div></div>
</div>
<div class="panel" style="margin-top:16px">
  <strong>Servidor y registro</strong>
  <dl class="estado" style="margin-top:10px">
    <dt>Versión de PHP</dt><dd><?= $h(PHP_VERSION) ?> <?= $phpVulnerable ? '<span class="chip mal">vulnerable a php2shell: actualizar a ' . $h($phpParche) . '+</span>' : '<span class="chip ok">con parche de php2shell</span>' ?></dd>
    <dt>session.upload_progress</dt><dd><?= $uploadProgressOn ? 'activado <span class="chip mal">recomendado: desactivar (MultiPHP INI Editor)</span>' : 'desactivado <span class="chip ok">bien</span>' ?></dd>
    <dt>Escritura en inc/</dt><dd><?= $dirEscribible ? 'sí <span class="chip ok">bien</span>' : '<b>NO</b> <span class="chip mal">no se registra nada</span>' ?></dd>
    <dt>Bitácora del mes</dt><dd><?= $logExiste ? number_format($logTam / 1048576, 2) . ' MB de ' . number_format($logTope / 1048576, 0) . ' MB de tope' . ($logTam > $logTope * 0.8 ? ' <span class="chip mal">casi llena</span>' : '') : '<b>aún no existe</b>' ?></dd>
    <dt>Último evento recibido</dt><dd><?= $ultimoEvento ? $h($fechaHora($ultimoEvento) . ' (' . $hace($ultimoEvento) . ')') : 'ninguno' ?></dd>
    <dt>Eventos en el periodo</dt><dd><?= $nf($eventosRango) ?><?= $R['pruebas'] ? ' · ' . $nf($R['pruebas']) . ' de pruebas internas' : '' ?></dd>
  </dl>
</div>

<p class="pie">
  <strong>Cómo se mide.</strong> Analítica propia y anónima: no se guarda IP, nombre, correo ni teléfono; solo un identificador aleatorio por navegador y por sesión, la familia de dispositivo/navegador y lo que se hace en el sitio. La navegación de quien tenga «No rastrear» (o haya abierto el sitio con <code>?notrack=1</code>) no se registra, pero <b>los envíos del formulario siempre se cuentan</b>: los de esos navegadores aparecen como <span class="chip prueba">prueba</span> y se pueden excluir con el interruptor de arriba. Una <em>sesión</em> es una visita continua de un navegador; un <em>contacto</em> es un envío del formulario o un clic en WhatsApp, teléfono o correo. Los clics indican intención, no garantizan que la persona haya escrito o llamado.
  Datos: <code>inc/eventos-AAAA-MM.log</code> (un archivo por mes) · <a href="<?= $url(['export' => 'csv']) ?>">descargar eventos crudos del periodo (CSV)</a>.
</p>

</div>
<div id="tip" role="status"></div>
<script>
(function(){
  var tip=document.getElementById('tip');
  document.querySelectorAll('svg.chart').forEach(function(svg){
    var xh=svg.querySelector('.xhair');
    svg.addEventListener('mousemove',function(e){
      var t=e.target;
      if(!t.classList||!t.classList.contains('hit')){tip.style.display='none';if(xh)xh.style.visibility='hidden';return;}
      if(xh){var x=t.getAttribute('data-x');xh.setAttribute('x1',x);xh.setAttribute('x2',x);xh.style.visibility='visible';}
      tip.textContent=t.getAttribute('data-tip');tip.style.display='block';
      var w=tip.offsetWidth,l=e.clientX+14;if(l+w>window.innerWidth-8)l=e.clientX-w-14;
      tip.style.left=l+'px';tip.style.top=(e.clientY+14)+'px';
    });
    svg.addEventListener('mouseleave',function(){tip.style.display='none';if(xh)xh.style.visibility='hidden';});
  });
})();
</script>
</body>
</html>
