<?php
/**
 * SIPCONS · reporte de contactos: envíos del formulario, clics a WhatsApp y a
 * teléfono. Abrir como /inc/stats.php?token=... con el 'stats_token' de
 * inc/db-config.php.
 */

declare(strict_types=1);
error_reporting(0);

$config = is_file(__DIR__ . '/db-config.php') ? require __DIR__ . '/db-config.php' : [];
$token  = (string)($config['stats_token'] ?? '');
if ($token === '' || !hash_equals($token, (string)($_GET['token'] ?? ''))) {
    http_response_code(403);
    exit('Acceso denegado.');
}

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

date_default_timezone_set('America/Tijuana');
$etiquetas = ['formulario' => 'Formulario enviado', 'whatsapp' => 'Clic a WhatsApp', 'telefono' => 'Clic a llamar'];

$total = array_fill_keys(array_keys($etiquetas), 0);
$hoy = $sem = $mes = $total;
$porDia = [];
$porPagina = [];

$ts0  = strtotime('today');
$ts7  = strtotime('-6 days', $ts0);
$ts30 = strtotime('-29 days', $ts0);

$archivo = __DIR__ . '/eventos.log';
if (is_file($archivo)) {
    foreach (file($archivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
        $ev = json_decode($linea, true);
        if (!is_array($ev) || !isset($etiquetas[$ev['e'] ?? ''])) continue;
        $e  = $ev['e'];
        $ts = strtotime((string)($ev['t'] ?? ''));
        if (!$ts) continue;
        $total[$e]++;
        if ($ts >= $ts0)  $hoy[$e]++;
        if ($ts >= $ts7)  $sem[$e]++;
        if ($ts >= $ts30) {
            $mes[$e]++;
            $dia = date('Y-m-d', $ts);
            $porDia[$dia][$e] = ($porDia[$dia][$e] ?? 0) + 1;
        }
        if ($e !== 'formulario') {
            $p = ($ev['p'] ?? '') !== '' ? $ev['p'] : '/';
            $porPagina[$p][$e] = ($porPagina[$p][$e] ?? 0) + 1;
        }
    }
}
krsort($porDia);
uasort($porPagina, static fn($a, $b) => array_sum($b) <=> array_sum($a));
$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Contactos · SIPCONS</title>
<style>
  body{font:15px/1.5 system-ui,Arial,sans-serif;margin:0;padding:24px;background:#f4f6fa;color:#0f1a2e}
  h1{font-size:22px;margin:0 0 20px} h2{font-size:16px;margin:28px 0 10px}
  table{border-collapse:collapse;background:#fff;border:1px solid #dde4ee;width:100%;max-width:760px}
  th,td{padding:9px 14px;text-align:right;border-bottom:1px solid #edf1f7}
  th:first-child,td:first-child{text-align:left} th{background:#eef2f8;font-size:13px}
  .wrap{overflow-x:auto}
</style>
</head>
<body>
<h1>Contactos desde sipcons.com</h1>

<div class="wrap"><table>
  <tr><th>Tipo</th><th>Hoy</th><th>Últimos 7 días</th><th>Últimos 30 días</th><th>Total</th></tr>
  <?php foreach ($etiquetas as $k => $nombre): ?>
  <tr><td><?= $h($nombre) ?></td><td><?= $hoy[$k] ?></td><td><?= $sem[$k] ?></td><td><?= $mes[$k] ?></td><td><strong><?= $total[$k] ?></strong></td></tr>
  <?php endforeach; ?>
</table></div>

<h2>Por día (últimos 30 días)</h2>
<div class="wrap"><table>
  <tr><th>Día</th><?php foreach ($etiquetas as $nombre): ?><th><?= $h($nombre) ?></th><?php endforeach; ?></tr>
  <?php foreach ($porDia as $dia => $c): ?>
  <tr><td><?= $h($dia) ?></td><?php foreach ($etiquetas as $k => $_): ?><td><?= $c[$k] ?? 0 ?></td><?php endforeach; ?></tr>
  <?php endforeach; ?>
  <?php if (!$porDia): ?><tr><td colspan="4">Todavía no hay eventos registrados.</td></tr><?php endif; ?>
</table></div>

<h2>Páginas desde donde más contactan (WhatsApp y teléfono, histórico)</h2>
<div class="wrap"><table>
  <tr><th>Página</th><th>WhatsApp</th><th>Llamar</th></tr>
  <?php foreach (array_slice($porPagina, 0, 15, true) as $p => $c): ?>
  <tr><td><?= $h($p) ?></td><td><?= $c['whatsapp'] ?? 0 ?></td><td><?= $c['telefono'] ?? 0 ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$porPagina): ?><tr><td colspan="3">Sin datos todavía.</td></tr><?php endif; ?>
</table></div>
</body>
</html>
