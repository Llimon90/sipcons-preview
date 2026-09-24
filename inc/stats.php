<?php
/**
 * SIPCONS · panel de analítica: comportamiento de visitantes, contactos
 * (formulario, WhatsApp, teléfono, correo), fuentes, dispositivos, productos,
 * catálogo y horarios.
 *
 * Abrir como /inc/stats.php?token=... (el 'stats_token' de inc/db-config.php).
 * Opciones: &dias=7|30|90  ·  &export=csv (eventos crudos del periodo).
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

$dias = (int)($_GET['dias'] ?? 30);
if (!in_array($dias, [7, 30, 90], true)) $dias = 30;

$ahora = time();
$hoy0  = strtotime('today');
$desde = strtotime('-' . ($dias - 1) . ' days', $hoy0);
$desdePrev = strtotime('-' . $dias . ' days', $desde);
$hastaPrev = $desde - 1;

// ---------------- Exportación CSV ----------------
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="sipcons-eventos-' . date('Y-m-d') . '.csv"');
    header('X-Robots-Tag: noindex, nofollow');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['fecha', 'evento', 'pagina', 'sesion', 'visitante', 'dispositivo', 'navegador', 'sistema', 'origen', 'utm_source', 'utm_medium', 'utm_campaign', 'lugar', 'producto', 'texto', 'resultados', 'interes', 'ms_activos', 'scroll_pct']);
    foreach (sipcons_leer_eventos($desde, $ahora) as $ev) {
        fputcsv($out, [
            $ev['t'], $ev['e'], $ev['p'] ?? '', $ev['s'] ?? '', $ev['v'] ?? '', $ev['d'] ?? '', $ev['b'] ?? '', $ev['o'] ?? '',
            $ev['r'] ?? '', $ev['us'] ?? '', $ev['um'] ?? '', $ev['uc'] ?? '', $ev['lg'] ?? '', $ev['pr'] ?? '', $ev['q'] ?? '',
            $ev['rs'] ?? '', $ev['i'] ?? '', $ev['ms'] ?? '', $ev['sc'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$R = sipcons_analizar($desde, $ahora);
$P = sipcons_analizar($desdePrev, $hastaPrev);

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
    return $s < 60 ? $s . ' s' : intdiv($s, 60) . ' min ' . ($s % 60) . ' s';
};
$nombreProducto = static fn(string $slug): string => $titulos[$slug] ?? strtoupper(str_replace('-', ' ', $slug));
$contactosDe = static fn(array $r): int => (int)array_sum(array_intersect_key($r['por_tipo'], array_flip(SIPCONS_CONTACTOS)));
$delta = static function ($actual, $previo, bool $subirEsBueno = true) use ($dias): string {
    if ($previo <= 0) return $actual > 0 ? '<span class="delta neutro">nuevo vs ' . $dias . ' días previos</span>' : '';
    $c = ($actual - $previo) / $previo * 100;
    if (abs($c) < 0.5) return '<span class="delta neutro">= sin cambio vs periodo previo</span>';
    $sube = $c > 0;
    $bueno = $sube === $subirEsBueno;
    return '<span class="delta ' . ($bueno ? 'bien' : 'mal') . '">' . ($sube ? '▲ ' : '▼ ') . number_format(abs($c), 0) . '% <small>' . ($bueno ? 'mejor' : 'peor') . ' que el periodo previo</small></span>';
};

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
$hoyDatos = $R['por_dia'][$hoyClave] ?? [];
$contactosHoy = 0;
foreach (SIPCONS_CONTACTOS as $c) $contactosHoy += (int)($hoyDatos[$c] ?? 0);

// ---------------- Series por día ----------------
$fechas = [];
for ($t = $desde; $t <= $hoy0; $t = strtotime('+1 day', $t)) $fechas[] = date('Y-m-d', $t);
$meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
$diasSem = [1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb', 7 => 'Dom'];
$etDia = static fn(string $f): string => (int)substr($f, 8, 2) . ' ' . $meses[(int)substr($f, 5, 2) - 1];
$serieVistas = [];
$serieContactos = ['formulario' => [], 'telefono' => [], 'whatsapp' => [], 'correo' => []];
foreach ($fechas as $f) {
    $serieVistas[] = (int)($R['por_dia'][$f]['vistas'] ?? 0);
    foreach ($serieContactos as $k => $_) $serieContactos[$k][] = (int)($R['por_dia'][$f][$k] ?? 0);
}
$nombresCanal = ['whatsapp' => 'WhatsApp', 'telefono' => 'Llamada', 'correo' => 'Correo', 'formulario' => 'Formulario'];

function techo_bonito(float $v): float {
    // Techo = 4 divisiones enteras y "redondas" (p. ej. 40 -> 0/10/20/30/40).
    if ($v <= 4) return 4;
    $bruto = $v / 4;
    $base = 10 ** floor(log10($bruto));
    foreach ([1, 2, 5, 10] as $m) if ($bruto <= $m * $base) return 4 * $m * $base;
    return 40 * $base;
}

/** Gráfica de línea con área (una serie). */
function svg_linea(array $etiquetas, array $vals, string $unidad, string $idc, callable $etDia): string {
    $W = 760; $H = 220; $ml = 44; $mr = 14; $mt = 14; $mb = 28;
    $n = count($vals);
    $max = techo_bonito((float)max($vals ?: [0]));
    $pw = $W - $ml - $mr; $ph = $H - $mt - $mb;
    $x = static fn(int $i): float => $ml + ($n > 1 ? $i * $pw / ($n - 1) : $pw / 2);
    $y = static fn(float $v): float => $mt + $ph - ($max > 0 ? $v / $max * $ph : 0);
    $s = '<svg class="chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . htmlspecialchars($unidad) . ' por día" data-chart="' . $idc . '">';
    for ($k = 0; $k <= 4; $k++) {
        $v = $max * $k / 4; $yy = $y($v);
        $s .= '<line class="gl" x1="' . $ml . '" x2="' . ($W - $mr) . '" y1="' . $yy . '" y2="' . $yy . '"/>';
        $s .= '<text class="tick" x="' . ($ml - 8) . '" y="' . ($yy + 4) . '" text-anchor="end">' . number_format($v, 0) . '</text>';
    }
    $paso = max(1, (int)ceil($n / 7));
    foreach ($etiquetas as $i => $f) {
        if ($i % $paso === 0) $s .= '<text class="tick" x="' . $x($i) . '" y="' . ($H - 8) . '" text-anchor="middle">' . htmlspecialchars($etDia($f)) . '</text>';
    }
    $pts = [];
    foreach ($vals as $i => $v) $pts[] = round($x($i), 1) . ',' . round($y((float)$v), 1);
    if ($n > 1) {
        $s .= '<polygon class="area" points="' . round($x(0), 1) . ',' . round($y(0), 1) . ' ' . implode(' ', $pts) . ' ' . round($x($n - 1), 1) . ',' . round($y(0), 1) . '"/>';
        $s .= '<polyline class="linea" points="' . implode(' ', $pts) . '"/>';
    }
    $s .= '<circle class="punto" cx="' . round($x($n - 1), 1) . '" cy="' . round($y((float)$vals[$n - 1]), 1) . '" r="4"/>';
    $s .= '<line class="xhair" y1="' . $mt . '" y2="' . ($mt + $ph) . '" x1="0" x2="0"/>';
    $slot = $n > 0 ? $pw / max(1, $n - ($n > 1 ? 1 : 0)) : $pw;
    foreach ($vals as $i => $v) {
        $tip = $etDia($etiquetas[$i]) . ' — ' . number_format($v) . ' ' . $unidad;
        $s .= '<rect class="hit" x="' . round($x($i) - $slot / 2, 1) . '" y="' . $mt . '" width="' . round($slot, 1) . '" height="' . $ph . '" data-x="' . round($x($i), 1) . '" data-tip="' . htmlspecialchars($tip) . '"/>';
    }
    return $s . '</svg>';
}

/** Columnas apiladas por canal (2px de separación, extremo superior redondeado). */
function svg_apiladas(array $etiquetas, array $series, array $nombres, array $clases, string $idc, callable $etDia): string {
    $W = 760; $H = 220; $ml = 44; $mr = 14; $mt = 14; $mb = 28;
    $n = count($etiquetas);
    $tot = [];
    for ($i = 0; $i < $n; $i++) { $tot[$i] = 0; foreach ($series as $v) $tot[$i] += $v[$i]; }
    $max = techo_bonito((float)max($tot ?: [0]));
    $pw = $W - $ml - $mr; $ph = $H - $mt - $mb;
    $slot = $pw / max(1, $n);
    $bw = min(24.0, max(3.0, $slot * 0.62));
    $y = static fn(float $v): float => $mt + $ph - ($max > 0 ? $v / $max * $ph : 0);
    $s = '<svg class="chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Contactos por día" data-chart="' . $idc . '">';
    for ($k = 0; $k <= 4; $k++) {
        $v = $max * $k / 4; $yy = $y($v);
        $s .= '<line class="gl" x1="' . $ml . '" x2="' . ($W - $mr) . '" y1="' . $yy . '" y2="' . $yy . '"/>';
        $s .= '<text class="tick" x="' . ($ml - 8) . '" y="' . ($yy + 4) . '" text-anchor="end">' . number_format($v, 0) . '</text>';
    }
    $paso = max(1, (int)ceil($n / 7));
    for ($i = 0; $i < $n; $i++) {
        $cx = $ml + $slot * ($i + 0.5);
        if ($i % $paso === 0) $s .= '<text class="tick" x="' . round($cx, 1) . '" y="' . ($H - 8) . '" text-anchor="middle">' . htmlspecialchars($etDia($etiquetas[$i])) . '</text>';
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
        $tip = $etDia($etiquetas[$i]) . ' — ' . ($partes ? implode(' · ', $partes) : 'sin contactos');
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

// ---------------- Hallazgos automáticos ----------------
$hallazgos = [];
$add = static function (string $tipo, string $texto) use (&$hallazgos): void { $hallazgos[] = [$tipo, $texto]; };
$muestraChica = $T['sesiones'] < 30;

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
    if ($mov && $T['sesiones'] > 0 && $mov['ses'] / $T['sesiones'] > 0.5) $add('Dato', number_format($mov['ses'] / $T['sesiones'] * 100, 0) . '% de las visitas llegan desde el celular: la versión móvil es la que más pesa.');
    if ($mov && $esc && $mov['ses'] >= 15 && $esc['ses'] >= 15) {
        $cm = ($mov['conv'] ?? 0) / $mov['ses']; $ce = ($esc['conv'] ?? 0) / $esc['ses'];
        if ($ce > 0 && $cm < $ce * 0.7) $add('Oportunidad', 'En móvil solo ' . number_format($cm * 100, 1) . '% contacta vs ' . number_format($ce * 100, 1) . '% en escritorio: hay margen para mejorar botones y velocidad en celular.');
    }
    if ($contactos >= 5) {
        $hh = array_fill(0, 24, 0); $dd = array_fill(1, 7, 0);
        foreach ($R['heat_contactos'] as $d => $fila) foreach ($fila as $h0 => $v) { $hh[$h0] += $v; $dd[$d] += $v; }
        arsort($hh); arsort($dd);
        $hp = (int)array_key_first($hh); $dp = (int)array_key_first($dd);
        $add('Dato', 'Más contactos: ' . $diasSem[$dp] . ' y de ' . $hp . ':00 a ' . ($hp + 1) . ':00. Conviene tener a alguien atendiendo WhatsApp en ese horario.');
    }
    $tc = array_sum(array_intersect_key($R['por_tipo'], array_flip(SIPCONS_CONTACTOS)));
    if ($tc >= 5 && ($R['por_tipo']['whatsapp'] ?? 0) / $tc > 0.5) $add('Dato', number_format(($R['por_tipo']['whatsapp'] ?? 0) / $tc * 100, 0) . '% de los contactos es por WhatsApp: es el canal principal.');
    $sinCot = [];
    foreach ($R['productos'] as $slug => $d) if (($d['vistas'] ?? 0) >= 8 && ($d['cotizar'] ?? 0) === 0) $sinCot[] = $nombreProducto((string)$slug) . ' (' . $d['vistas'] . ' vistas)';
    if ($sinCot) $add('Oportunidad', 'Productos con visitas pero sin cotizaciones: ' . implode(', ', array_slice($sinCot, 0, 3)) . '. Revisa foto, ficha técnica y descripción.');
    $sinRes = [];
    foreach ($R['busquedas'] as $q => $d) if (($d['sin'] ?? 0) > 0) $sinRes[] = '«' . $q . '»';
    if ($sinRes) $add('Oportunidad', 'Buscaron y no encontraron: ' . implode(', ', array_slice($sinRes, 0, 4)) . '. Es demanda que hoy el catálogo no cubre.');
    $ini = (int)($R['por_tipo']['form_inicio'] ?? 0); $env = (int)($R['por_tipo']['formulario'] ?? 0);
    if ($ini >= 5 && $env / $ini < 0.5) $add('Alerta', 'Abandono del formulario: ' . $ini . ' personas empezaron a llenarlo y solo ' . $env . ' lo enviaron (' . number_format((1 - $env / $ini) * 100, 0) . '% lo deja a medias). Considera pedir menos campos.');
    if ($T['sesiones'] >= 30 && $rebote > 60) $add('Alerta', 'Rebote alto (' . number_format($rebote, 0) . '%): más de la mitad se va sin interactuar. Revisa la portada y la velocidad de carga.');
    $bajo = null;
    foreach ($R['paginas'] as $p => $d) if (($d['n_sc'] ?? 0) >= 10 && ($d['vistas'] ?? 0) >= 15) { $sc = $d['sc'] / $d['n_sc']; if ($bajo === null || $sc < $bajo[1]) $bajo = [$p, $sc]; }
    if ($bajo && $bajo[1] < 40) $add('Oportunidad', 'En ' . $bajo[0] . ' la gente solo baja hasta el ' . number_format($bajo[1], 0) . '% de la página: lo importante debería ir más arriba.');
    if ($R['e404']) $add('Alerta', 'Enlaces rotos: ' . $nf(array_sum($R['e404'])) . ' visitas cayeron en «Página no encontrada» (la más frecuente: ' . (string)array_key_first($R['e404']) . ').');
}

$estilos = ['Dato' => 'dato', 'Oportunidad' => 'op', 'Alerta' => 'alerta'];

function fila_barra(float $valor, float $max, string $clase = ''): string {
    $w = $max > 0 ? max(0, min(100, $valor / $max * 100)) : 0;
    return '<div class="barra ' . $clase . '"><span style="width:' . round($w, 1) . '%"></span></div>';
}
function tabla_dim(array $datos, string $titulo, callable $h, callable $pct, int $limite = 8): string {
    $tot = array_sum(array_column($datos, 'ses'));
    $s = '<div class="tabla-scroll"><table><thead><tr><th>' . $h($titulo) . '</th><th>Sesiones</th><th>%</th><th>Contactan</th><th>Rebote</th></tr></thead><tbody>';
    foreach (array_slice($datos, 0, $limite, true) as $k => $d) {
        $s .= '<tr><td>' . $h($k) . '</td><td>' . $d['ses'] . '</td><td>' . $pct($d['ses'], $tot, 0) . '</td><td>' . $pct($d['conv'] ?? 0, $d['ses']) . '</td><td>' . $pct($d['reb'] ?? 0, $d['ses'], 0) . '</td></tr>';
    }
    if (!$datos) $s .= '<tr><td colspan="5" class="vacio">Sin datos todavía.</td></tr>';
    return $s . '</tbody></table></div>';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
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
  body{margin:0;background:var(--plano);color:var(--tinta);font:15px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}
  .wrap{max-width:1180px;margin:0 auto;padding:24px 20px 64px}
  header.top{display:flex;flex-wrap:wrap;gap:12px 24px;align-items:flex-end;justify-content:space-between;margin-bottom:8px}
  h1{font-size:24px;margin:0} .sub{color:var(--tinta2);font-size:13px;margin:2px 0 0}
  .rango{display:flex;gap:6px;flex-wrap:wrap}
  .rango a{padding:7px 14px;border-radius:999px;border:1px solid var(--borde);background:var(--sup);color:var(--tinta);text-decoration:none;font-size:13px}
  .rango a.act{background:var(--navy);color:#fff;border-color:var(--navy);font-weight:600}
  .rango a:focus-visible,summary:focus-visible{outline:2px solid var(--s1);outline-offset:2px}
  h2{font-size:17px;margin:34px 0 4px} h2 + .nota{margin-top:0}
  .nota{color:var(--tinta2);font-size:13px;margin:0 0 12px}
  .panel{background:var(--sup);border:1px solid var(--borde);border-radius:14px;padding:18px 20px}
  .grid{display:grid;gap:16px}
  .g2{grid-template-columns:repeat(2,minmax(0,1fr))} .g3{grid-template-columns:repeat(3,minmax(0,1fr))}
  @media (max-width:900px){.g2,.g3{grid-template-columns:1fr}}
  .aviso{background:#fff7e0;color:#5a4300;border:1px solid #f0d78a;border-radius:10px;padding:10px 14px;font-size:13px;margin:12px 0}
  @media (prefers-color-scheme:dark){.aviso{background:#2e2608;color:#f0d78a;border-color:#5a4a12}}
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
  th{font-size:12px;color:var(--tinta2);font-weight:600;background:transparent}
  td.vacio{text-align:center;color:var(--tenue);padding:18px}
  .barra{height:8px;background:var(--linea);border-radius:4px;min-width:90px;overflow:hidden}
  .barra span{display:block;height:100%;background:var(--s1);border-radius:4px}
  .barra.o span{background:var(--s2)}
  /* embudo */
  .embudo{display:grid;gap:10px}
  .paso{display:grid;grid-template-columns:200px 1fr 230px;gap:12px;align-items:center;font-size:13.5px}
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
  .pie{margin-top:40px;color:var(--tenue);font-size:12.5px;max-width:78ch}
  .pie a{color:var(--s1)}
</style>
</head>
<body>
<div class="wrap">

<header class="top">
  <div>
    <h1>Analítica de sipcons.com</h1>
    <p class="sub">Del <?= $h($etDia(date('Y-m-d', $desde))) ?> al <?= $h($etDia($hoyClave)) ?> · <?= $dias ?> días · comparado con los <?= $dias ?> días anteriores · hora de Tijuana</p>
  </div>
  <nav class="rango" aria-label="Periodo">
    <?php foreach ([7 => '7 días', 30 => '30 días', 90 => '90 días'] as $d => $et): ?>
      <a href="?token=<?= $h(rawurlencode($token)) ?>&amp;dias=<?= $d ?>" class="<?= $d === $dias ? 'act' : '' ?>"><?= $et ?></a>
    <?php endforeach; ?>
    <a href="?token=<?= $h(rawurlencode($token)) ?>&amp;dias=<?= $dias ?>&amp;export=csv">Exportar CSV</a>
  </nav>
</header>

<?php if ($muestraChica): ?>
<div class="aviso"><strong>Muestra pequeña (<?= $T['sesiones'] ?> sesiones).</strong> Los porcentajes con pocas visitas cambian mucho de un día a otro: úsalos como tendencia, no como conclusión. Los datos empezaron a registrarse con esta versión, y tus propias visitas cuentan a menos que abras el sitio una vez con <code>?notrack=1</code> en ese navegador.</div>
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
  </div>
  <div class="kpi"><div class="et">Visitantes únicos</div><div class="val"><?= $nf($T['visitantes']) ?></div><?= $delta($T['visitantes'], $TP['visitantes']) ?><div><small><?= $nf($T['visitantes_nuevos']) ?> nuevos</small></div></div>
  <div class="kpi"><div class="et">Sesiones</div><div class="val"><?= $nf($T['sesiones']) ?></div><?= $delta($T['sesiones'], $TP['sesiones']) ?><div><small><?= number_format($pagsPorSesion, 1) ?> páginas por sesión</small></div></div>
  <div class="kpi"><div class="et">Vistas de página</div><div class="val"><?= $nf($T['vistas']) ?></div><?= $delta($T['vistas'], $TP['vistas']) ?></div>
  <div class="kpi"><div class="et">Sesiones que contactan</div><div class="val"><?= number_format($conv, 1) ?>%</div><?= $delta($conv, $convPrev) ?><div><small><?= $nf($T['con_contacto']) ?> de <?= $nf($T['sesiones']) ?></small></div></div>
  <div class="kpi"><div class="et">Tiempo activo por sesión</div><div class="val"><?= $h($dur((float)$msProm)) ?></div><small>solo cuenta pestañas visibles</small></div>
  <div class="kpi"><div class="et">Rebote</div><div class="val"><?= number_format($rebote, 0) ?>%</div><?= $delta($rebote, $rebotePrev, false) ?><div><small>1 página, sin interactuar, &lt; 15 s</small></div></div>
</section>

<h2>Hallazgos</h2>
<p class="nota">Lecturas automáticas de los datos del periodo. Se calculan solas; si no hay suficiente muestra, no aparecen.</p>
<?php if ($hallazgos): ?>
<ul class="hall">
  <?php foreach ($hallazgos as [$tipo, $texto]): ?>
  <li><span class="tag <?= $estilos[$tipo] ?>"><?= $h($tipo) ?></span><span><?= $h($texto) ?></span></li>
  <?php endforeach; ?>
</ul>
<?php else: ?>
<div class="panel"><span class="vacio">Todavía no hay suficientes datos para sacar conclusiones.</span></div>
<?php endif; ?>

<h2>Tendencia</h2>
<div class="grid g2">
  <div class="panel">
    <strong>Vistas de página por día</strong>
    <p class="nota">Cada carga de una página cuenta como una vista.</p>
    <?= svg_linea($fechas, $serieVistas, 'vistas', 'vistas', $etDia) ?>
    <details><summary>Ver tabla</summary><div class="tabla-scroll"><table><thead><tr><th>Día</th><th>Vistas</th></tr></thead><tbody>
      <?php foreach (array_reverse($fechas, true) as $i => $f): ?><tr><td><?= $h($etDia($f)) ?></td><td><?= $serieVistas[$i] ?></td></tr><?php endforeach; ?>
    </tbody></table></div></details>
  </div>
  <div class="panel">
    <strong>Contactos por día</strong>
    <div class="leyenda">
      <?php foreach (['formulario' => '--s1', 'telefono' => '--s2', 'whatsapp' => '--s3', 'correo' => '--s4'] as $k => $var): ?>
        <span><i style="background:var(<?= $var ?>)"></i><?= $h($nombresCanal[$k]) ?></span>
      <?php endforeach; ?>
    </div>
    <?= svg_apiladas($fechas, $serieContactos, $nombresCanal, ['formulario' => 'c-formulario', 'telefono' => 'c-telefono', 'whatsapp' => 'c-whatsapp', 'correo' => 'c-correo'], 'contactos', $etDia) ?>
    <details><summary>Ver tabla</summary><div class="tabla-scroll"><table><thead><tr><th>Día</th><?php foreach ($nombresCanal as $n): ?><th><?= $h($n) ?></th><?php endforeach; ?></tr></thead><tbody>
      <?php foreach (array_reverse($fechas, true) as $i => $f): ?><tr><td><?= $h($etDia($f)) ?></td><?php foreach (array_keys($nombresCanal) as $k): ?><td><?= $serieContactos[$k][$i] ?? 0 ?></td><?php endforeach; ?></tr><?php endforeach; ?>
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

<h2>De dónde vienen</h2>
<p class="nota">La fuente se toma de la primera página de cada sesión (buscador, red social, enlace externo, campaña con parámetros utm o directo).</p>
<div class="panel"><?= tabla_dim($R['fuentes'], 'Fuente', $h, $pct, 12) ?></div>

<h2>Con qué entran</h2>
<div class="grid g3">
  <div class="panel"><strong>Dispositivo</strong><?= tabla_dim($R['dispositivos'], 'Tipo', $h, $pct) ?></div>
  <div class="panel"><strong>Navegador</strong><?= tabla_dim($R['navegadores'], 'Navegador', $h, $pct) ?></div>
  <div class="panel"><strong>Sistema</strong><?= tabla_dim($R['sistemas'], 'Sistema', $h, $pct) ?></div>
</div>

<h2>Páginas</h2>
<p class="nota">Tiempo y scroll promedio por visita. «Salidas» = veces que fue la última página de la sesión. «Contactos» = contactos hechos estando en esa página.</p>
<div class="panel tabla-scroll">
  <table><thead><tr><th>Página</th><th>Vistas</th><th>Visitas únicas</th><th>Tiempo prom.</th><th>Scroll prom.</th><th>Salidas</th><th>Contactos</th></tr></thead><tbody>
  <?php foreach (array_slice($R['paginas'], 0, 20, true) as $p => $d): $v = $d['visitas'] ?? 0; ?>
    <tr>
      <td><?= $h($p === '' ? '/' : ($titulos && ($s = sipcons_slug_de_pagina($p)) !== '' ? '/producto → ' . $nombreProducto($s) : $p)) ?></td>
      <td><?= $nf($d['vistas'] ?? 0) ?></td><td><?= $nf($v) ?></td>
      <td><?= $v > 0 ? $h($dur(($d['ms'] ?? 0) / $v)) : '—' ?></td>
      <td><?= ($d['n_sc'] ?? 0) > 0 ? number_format($d['sc'] / $d['n_sc'], 0) . '%' : '—' ?></td>
      <td><?= $pct($d['salidas'] ?? 0, $v, 0) ?></td>
      <td><?= $nf($d['contactos'] ?? 0) ?></td>
    </tr>
  <?php endforeach; if (!$R['paginas']): ?><tr><td colspan="7" class="vacio">Sin datos todavía.</td></tr><?php endif; ?>
  </tbody></table>
</div>

<h2>Páginas de entrada</h2>
<p class="nota">Primera página que ve cada visitante y qué tan bien convierte. Sirve para decidir a dónde mandar el tráfico de anuncios y redes.</p>
<div class="panel"><?= tabla_dim($R['landings'], 'Entrada', $h, $pct, 10) ?></div>

<h2>Productos: interés vs. acción</h2>
<p class="nota">Vistas de la ficha contra clics para cotizar (WhatsApp, llamada o correo estando en el producto). «Cotiza» = cotizaciones ÷ vistas.</p>
<div class="panel tabla-scroll">
  <table><thead><tr><th>Producto</th><th>Vistas de ficha</th><th>Cotizaciones</th><th>Cotiza</th><th>Ficha PDF</th><th>Galería</th></tr></thead><tbody>
  <?php foreach (array_slice($R['productos'], 0, 20, true) as $slug => $d): ?>
    <tr><td><?= $h($nombreProducto((string)$slug)) ?></td><td><?= $nf($d['vistas'] ?? 0) ?></td><td><?= $nf($d['cotizar'] ?? 0) ?></td><td><?= $pct($d['cotizar'] ?? 0, $d['vistas'] ?? 0, 0) ?></td><td><?= $nf($d['pdf'] ?? 0) ?></td><td><?= $nf($d['galeria'] ?? 0) ?></td></tr>
  <?php endforeach; if (!$R['productos']): ?><tr><td colspan="6" class="vacio">Sin datos todavía.</td></tr><?php endif; ?>
  </tbody></table>
  <?php if ($R['destacados']): ?>
    <p class="nota" style="margin-top:14px">Clics desde el carrusel de la portada: <?= $h(implode(' · ', array_map(fn($s, $n) => $nombreProducto((string)$s) . ' (' . $n . ')', array_keys(array_slice($R['destacados'], 0, 6, true)), array_slice($R['destacados'], 0, 6, true)))) ?></p>
  <?php endif; ?>
</div>

<h2>Catálogo: qué buscan</h2>
<div class="grid g2">
  <div class="panel"><strong>Filtros más usados</strong>
    <div class="tabla-scroll"><table><thead><tr><th>Filtro</th><th>Veces</th></tr></thead><tbody>
    <?php foreach (array_slice($R['filtros'], 0, 12, true) as $f => $n): ?><tr><td><?= $h($f) ?></td><td><?= $n ?></td></tr><?php endforeach; if (!$R['filtros']): ?><tr><td colspan="2" class="vacio">Sin datos todavía.</td></tr><?php endif; ?>
    </tbody></table></div></div>
  <div class="panel"><strong>Búsquedas de texto</strong>
    <div class="tabla-scroll"><table><thead><tr><th>Búsqueda</th><th>Veces</th><th>Sin resultados</th></tr></thead><tbody>
    <?php foreach (array_slice($R['busquedas'], 0, 12, true) as $q => $d): ?><tr><td><?= $h($q) ?></td><td><?= $d['n'] ?></td><td><?= $d['sin'] ?? 0 ?></td></tr><?php endforeach; if (!$R['busquedas']): ?><tr><td colspan="3" class="vacio">Sin datos todavía.</td></tr><?php endif; ?>
    </tbody></table></div></div>
</div>

<h2>Contactos: dónde y cómo</h2>
<div class="grid g2">
  <div class="panel"><strong>Desde qué botón contactan</strong>
    <p class="nota">Lugar de la página donde estaba el botón (flotante, ficha de producto, menú, pie…).</p>
    <div class="tabla-scroll"><table><thead><tr><th>Canal · lugar</th><th>Contactos</th><th></th></tr></thead><tbody>
    <?php
    $filasLugar = [];
    foreach ($R['lugares'] as $canal => $lugs) foreach ($lugs as $lg => $n) $filasLugar[$nombresCanal[$canal] . ' · ' . $lg] = $n;
    arsort($filasLugar);
    $maxL = $filasLugar ? max($filasLugar) : 0;
    foreach (array_slice($filasLugar, 0, 14, true) as $k => $n): ?>
      <tr><td><?= $h($k) ?></td><td><?= $n ?></td><td style="width:110px"><?= fila_barra((float)$n, (float)$maxL, 'o') ?></td></tr>
    <?php endforeach; if (!$filasLugar): ?><tr><td colspan="3" class="vacio">Sin datos todavía.</td></tr><?php endif; ?>
    </tbody></table></div></div>
  <div class="panel"><strong>Formulario de contacto</strong>
    <?php $ini = (int)($R['por_tipo']['form_inicio'] ?? 0); $env = (int)($R['por_tipo']['formulario'] ?? 0); ?>
    <p class="nota">Empezaron a llenarlo: <b><?= $ini ?></b> · Enviados: <b><?= $env ?></b> · Se van a medias: <b><?= $ini > 0 ? $pct(max(0, $ini - $env), $ini, 0) : '—' ?></b></p>
    <div class="tabla-scroll"><table><thead><tr><th>Interés declarado</th><th>Envíos</th></tr></thead><tbody>
    <?php foreach ($R['intereses'] as $i => $n): ?><tr><td><?= $h($i) ?></td><td><?= $n ?></td></tr><?php endforeach; if (!$R['intereses']): ?><tr><td colspan="2" class="vacio">Sin datos todavía.</td></tr><?php endif; ?>
    </tbody></table></div></div>
</div>

<h2>Horarios</h2>
<p class="nota">Cuándo entran y cuándo contactan, por día de la semana y hora (Tijuana). Color más intenso = más actividad. Útil para decidir turnos de atención y horarios de publicaciones.</p>
<div class="grid">
  <div class="panel"><strong>Vistas de página</strong><?= tabla_calor($R['heat_vistas'], 'vistas', 'az', $diasSem) ?></div>
  <div class="panel"><strong>Contactos</strong><?= tabla_calor($R['heat_contactos'], 'contactos', 'na', $diasSem) ?></div>
</div>

<h2>Salud del sitio</h2>
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

<p class="pie">
  <strong>Cómo se mide.</strong> Analítica propia y anónima: no se guarda IP, nombre, correo ni teléfono; solo un identificador aleatorio por navegador y por sesión, la familia de dispositivo/navegador y lo que se hace en el sitio. Se excluyen bots conocidos y a quien tenga activado «No rastrear». Una <em>sesión</em> es una visita continua de un navegador; un <em>contacto</em> es un envío del formulario o un clic en WhatsApp, teléfono o correo. Los clics indican intención, no garantizan que la persona haya escrito o llamado. Para no contar tus propias visitas, abre el sitio una vez con <code>?notrack=1</code> en cada navegador que uses (con <code>?notrack=0</code> se reactiva). Recuerda mantener actualizado el aviso de privacidad del sitio.
  Datos: <code>inc/eventos-AAAA-MM.log</code> (un archivo por mes) · <a href="?token=<?= $h(rawurlencode($token)) ?>&amp;dias=<?= $dias ?>&amp;export=csv">descargar eventos crudos (CSV)</a>.
</p>

</div>
<div id="tip" role="status"></div>
<script>
(function(){
  var tip=document.getElementById('tip');
  document.querySelectorAll('svg.chart').forEach(function(svg){
    var xh=svg.querySelector('.xhair'), vb=svg.viewBox.baseVal;
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
