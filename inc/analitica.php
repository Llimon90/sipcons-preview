<?php
/**
 * SIPCONS · lectura y agregación de los eventos guardados por inc/eventos.php.
 * Solo se usa desde inc/stats.php (nunca se visita directo).
 */

declare(strict_types=1);

const SIPCONS_CONTACTOS = ['whatsapp', 'telefono', 'correo', 'formulario'];
const SIPCONS_INTERACCIONES = ['filtro', 'busqueda', 'pdf', 'galeria', 'destacado', 'form_inicio'];

/** Lee (en streaming) los eventos entre dos timestamps, de los archivos mensuales. */
function sipcons_leer_eventos(int $desde, int $hasta): Generator {
    date_default_timezone_set('America/Tijuana');
    $archivos = [];
    for ($t = strtotime(date('Y-m-01', $desde)); $t <= $hasta; $t = strtotime('+1 month', $t)) {
        $archivos[] = __DIR__ . '/eventos-' . date('Y-m', $t) . '.log';
    }
    $archivos[] = __DIR__ . '/eventos.log'; // formato anterior (solo formulario/whatsapp/teléfono)

    foreach ($archivos as $archivo) {
        $fh = is_file($archivo) ? @fopen($archivo, 'r') : false;
        if (!$fh) continue;
        while (($linea = fgets($fh)) !== false) {
            $ev = json_decode($linea, true);
            if (!is_array($ev) || !isset($ev['e'], $ev['t'])) continue;
            $ts = strtotime((string)$ev['t']);
            if (!$ts || $ts < $desde || $ts > $hasta) continue;
            $ev['ts'] = $ts;
            yield $ev;
        }
        fclose($fh);
    }
}

function sipcons_host_limpio(string $host): string {
    return (string)preg_replace('/^(www|m|l|lm|mobile)\./', '', strtolower($host));
}

function sipcons_clasificar_fuente(string $ref, string $us, string $um): string {
    if ($us !== '') return 'Campaña: ' . $us . ($um !== '' ? ' / ' . $um : '');
    if ($ref === '') return 'Directo';
    $h = sipcons_host_limpio($ref);
    $buscadores = ['google' => 'Google', 'bing' => 'Bing', 'duckduckgo' => 'DuckDuckGo', 'yahoo' => 'Yahoo', 'ecosia' => 'Ecosia', 'brave' => 'Brave'];
    foreach ($buscadores as $clave => $nombre) {
        if (strpos($h, $clave) !== false) return 'Búsqueda: ' . $nombre;
    }
    $redes = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'twitter' => 'X / Twitter', 't.co' => 'X / Twitter', 'x.com' => 'X / Twitter',
              'linkedin' => 'LinkedIn', 'youtube' => 'YouTube', 'tiktok' => 'TikTok', 'pinterest' => 'Pinterest', 'whatsapp' => 'WhatsApp'];
    foreach ($redes as $clave => $nombre) {
        if (strpos($h, $clave) !== false) return 'Redes: ' . $nombre;
    }
    return 'Referido: ' . $h;
}

function sipcons_slug_de_pagina(string $p): string {
    if (strpos($p, '/producto.php') !== 0) return '';
    return preg_match('/[?&]slug=([^&]+)/', $p, $m) ? rawurldecode($m[1]) : '';
}

/** @param array<string,array<string,int>> $tabla */
function sipcons_sumar_sesion(array &$tabla, string $k, bool $convierte, bool $rebote): void {
    $tabla[$k]['ses'] = ($tabla[$k]['ses'] ?? 0) + 1;
    if ($convierte) $tabla[$k]['conv'] = ($tabla[$k]['conv'] ?? 0) + 1;
    if ($rebote) $tabla[$k]['reb'] = ($tabla[$k]['reb'] ?? 0) + 1;
}

/** @param array<string,int> $a */
function sipcons_inc(array &$a, string $k, int $n = 1): void {
    $a[$k] = ($a[$k] ?? 0) + $n;
}

/**
 * Agrega todos los eventos del rango. Devuelve KPIs, series, rankings y tablas.
 * @return array<string,mixed>
 */
function sipcons_analizar(int $desde, int $hasta): array {
    $porTipo = [];
    $porDia = [];
    $heatVistas = array_fill(1, 7, array_fill(0, 24, 0));
    $heatContactos = array_fill(1, 7, array_fill(0, 24, 0));
    $sesiones = [];
    $visitas = [];
    $paginas = [];
    $productos = [];
    $lugares = [];
    $filtros = [];
    $busquedas = [];
    $intereses = [];
    $e404 = [];
    $salientes = [];
    $destacados = [];
    $sinSesion = array_fill_keys(SIPCONS_CONTACTOS, 0);

    foreach (sipcons_leer_eventos($desde, $hasta) as $ev) {
        $e = (string)$ev['e'];
        $ts = (int)$ev['ts'];
        $p = (string)($ev['p'] ?? '');
        $s = (string)($ev['s'] ?? '');
        $dia = date('Y-m-d', $ts);
        $esContacto = in_array($e, SIPCONS_CONTACTOS, true);

        sipcons_inc($porTipo, $e);

        if ($e === 'vista') {
            $porDia[$dia]['vistas'] = ($porDia[$dia]['vistas'] ?? 0) + 1;
            $heatVistas[(int)date('N', $ts)][(int)date('G', $ts)]++;
        }
        if ($esContacto) {
            $porDia[$dia][$e] = ($porDia[$dia][$e] ?? 0) + 1;
            $heatContactos[(int)date('N', $ts)][(int)date('G', $ts)]++;
            $lg = (string)($ev['lg'] ?? '');
            $lugarClave = $lg !== '' ? $lg : ($e === 'formulario' ? 'formulario' : 'sin dato');
            $lugares[$e][$lugarClave] = ($lugares[$e][$lugarClave] ?? 0) + 1;
            if ($p !== '') $paginas[$p]['contactos'] = ($paginas[$p]['contactos'] ?? 0) + 1;
            $pr = (string)($ev['pr'] ?? '');
            if ($pr !== '') $productos[$pr]['cotizar'] = ($productos[$pr]['cotizar'] ?? 0) + 1;
            if ($e === 'formulario' && !empty($ev['i'])) sipcons_inc($intereses, (string)$ev['i']);
            if ($s === '') $sinSesion[$e]++;
        }

        switch ($e) {
            case 'filtro':
                sipcons_inc($filtros, (string)($ev['lg'] ?? '') . ': ' . (string)($ev['q'] ?? ''));
                break;
            case 'busqueda':
                $q = mb_strtolower((string)($ev['q'] ?? ''));
                if ($q !== '') {
                    $busquedas[$q]['n'] = ($busquedas[$q]['n'] ?? 0) + 1;
                    if ((int)($ev['rs'] ?? 1) === 0) $busquedas[$q]['sin'] = ($busquedas[$q]['sin'] ?? 0) + 1;
                }
                break;
            case 'error404':
                sipcons_inc($e404, $p !== '' ? $p : '(desconocida)');
                break;
            case 'saliente':
                sipcons_inc($salientes, (string)($ev['lg'] ?? ''));
                break;
            case 'destacado':
                if (!empty($ev['pr'])) sipcons_inc($destacados, (string)$ev['pr']);
                break;
            case 'pdf':
                if (!empty($ev['pr'])) $productos[$ev['pr']]['pdf'] = ($productos[$ev['pr']]['pdf'] ?? 0) + 1;
                break;
            case 'galeria':
                if (!empty($ev['pr'])) $productos[$ev['pr']]['galeria'] = ($productos[$ev['pr']]['galeria'] ?? 0) + 1;
                break;
        }

        if ($s === '') continue;

        if (!isset($sesiones[$s])) {
            $sesiones[$s] = [
                'v' => (string)($ev['v'] ?? '') ?: $s, 'vistas' => 0, 'ms' => 0,
                'd' => (string)($ev['d'] ?? 'Escritorio'), 'b' => (string)($ev['b'] ?? 'Otro'), 'o' => (string)($ev['o'] ?? 'Otro'),
                'fuente' => null, 'landing' => null, 'nuevo' => false, 'contactos' => 0,
                'productos' => false, 'interacciones' => 0, 'ultima' => '',
            ];
        }
        $ses =& $sesiones[$s];

        if ($e === 'vista') {
            $ses['vistas']++;
            $ses['ultima'] = $p;
            if ($ses['landing'] === null) {
                $ses['landing'] = $p;
                $ses['fuente'] = sipcons_clasificar_fuente((string)($ev['r'] ?? ''), (string)($ev['us'] ?? ''), (string)($ev['um'] ?? ''));
                $ses['nuevo'] = !empty($ev['n']);
            }
            if (strpos($p, '/producto.php') === 0 || strpos($p, '/productos.php') === 0) $ses['productos'] = true;
            $paginas[$p]['vistas'] = ($paginas[$p]['vistas'] ?? 0) + 1;
            $clave = $s . '|' . $p;
            if (!isset($visitas[$clave])) {
                $visitas[$clave] = ['p' => $p, 'ms' => 0, 'sc' => null];
                $slug = sipcons_slug_de_pagina($p);
                if ($slug !== '') $productos[$slug]['vistas'] = ($productos[$slug]['vistas'] ?? 0) + 1;
            }
        } elseif ($e === 'salida') {
            $ms = (int)($ev['ms'] ?? 0);
            $ses['ms'] += $ms;
            $clave = $s . '|' . $p;
            if (isset($visitas[$clave])) {
                $visitas[$clave]['ms'] += $ms;
                if (isset($ev['sc'])) $visitas[$clave]['sc'] = max((int)$visitas[$clave]['sc'], (int)$ev['sc']);
            }
        } elseif ($esContacto) {
            $ses['contactos']++;
        } elseif (in_array($e, SIPCONS_INTERACCIONES, true)) {
            $ses['interacciones']++;
        }
        unset($ses);
    }

    // --- Agregados por sesión -------------------------------------------
    $t = ['sesiones' => 0, 'vistas' => 0, 'con_contacto' => 0, 'rebotes' => 0, 'nuevos' => 0, 'ms' => 0, 'con_ms' => 0];
    $visitantes = [];
    $nuevosV = [];
    $fuentes = [];
    $dispositivos = [];
    $navegadores = [];
    $sistemas = [];
    $landings = [];
    $salidasPorPagina = [];
    $embudo = ['sesiones' => 0, 'productos' => 0, 'interaccion' => 0, 'contacto' => 0];

    foreach ($sesiones as $ses) {
        $t['sesiones']++;
        $t['vistas'] += $ses['vistas'];
        $visitantes[$ses['v']] = true;
        $convierte = $ses['contactos'] > 0;
        $rebote = $ses['vistas'] <= 1 && $ses['interacciones'] === 0 && !$convierte && $ses['ms'] < 15000;
        if ($convierte) $t['con_contacto']++;
        if ($rebote) $t['rebotes']++;
        if ($ses['nuevo']) { $t['nuevos']++; $nuevosV[$ses['v']] = true; }
        if ($ses['ms'] > 0) { $t['ms'] += $ses['ms']; $t['con_ms']++; }

        sipcons_sumar_sesion($fuentes, $ses['fuente'] ?? 'Directo', $convierte, $rebote);
        sipcons_sumar_sesion($dispositivos, $ses['d'], $convierte, $rebote);
        sipcons_sumar_sesion($navegadores, $ses['b'], $convierte, $rebote);
        sipcons_sumar_sesion($sistemas, $ses['o'], $convierte, $rebote);
        sipcons_sumar_sesion($landings, $ses['landing'] ?? '(sin datos)', $convierte, $rebote);
        if ($ses['ultima'] !== '') sipcons_inc($salidasPorPagina, $ses['ultima']);

        $embudo['sesiones']++;
        if ($ses['productos']) $embudo['productos']++;
        if ($ses['interacciones'] > 0 || $convierte) $embudo['interaccion']++;
        if ($convierte) $embudo['contacto']++;
    }

    // --- Métricas por página (por visita = sesión × página) ---------------
    foreach ($visitas as $vis) {
        $paginas[$vis['p']]['visitas'] = ($paginas[$vis['p']]['visitas'] ?? 0) + 1;
        $paginas[$vis['p']]['ms'] = ($paginas[$vis['p']]['ms'] ?? 0) + $vis['ms'];
        if ($vis['sc'] !== null) {
            $paginas[$vis['p']]['sc'] = ($paginas[$vis['p']]['sc'] ?? 0) + $vis['sc'];
            $paginas[$vis['p']]['n_sc'] = ($paginas[$vis['p']]['n_sc'] ?? 0) + 1;
        }
    }
    foreach ($salidasPorPagina as $p => $n) $paginas[$p]['salidas'] = $n;

    $ordenarPorVistas = static fn($a, $b) => ($b['vistas'] ?? 0) <=> ($a['vistas'] ?? 0);
    uasort($paginas, $ordenarPorVistas);
    uasort($productos, static fn($a, $b) => (($b['vistas'] ?? 0) + ($b['cotizar'] ?? 0) * 5) <=> (($a['vistas'] ?? 0) + ($a['cotizar'] ?? 0) * 5));
    $porSesiones = static fn($a, $b) => $b['ses'] <=> $a['ses'];
    uasort($fuentes, $porSesiones);
    uasort($dispositivos, $porSesiones);
    uasort($navegadores, $porSesiones);
    uasort($sistemas, $porSesiones);
    uasort($landings, $porSesiones);
    arsort($filtros);
    arsort($e404);
    arsort($salientes);
    arsort($destacados);
    arsort($intereses);
    uasort($busquedas, static fn($a, $b) => $b['n'] <=> $a['n']);

    return [
        'totales'       => $t + ['visitantes' => count($visitantes), 'visitantes_nuevos' => count($nuevosV)],
        'por_tipo'      => $porTipo,
        'por_dia'       => $porDia,
        'heat_vistas'   => $heatVistas,
        'heat_contactos' => $heatContactos,
        'fuentes'       => $fuentes,
        'dispositivos'  => $dispositivos,
        'navegadores'   => $navegadores,
        'sistemas'      => $sistemas,
        'landings'      => $landings,
        'embudo'        => $embudo,
        'paginas'       => $paginas,
        'productos'     => $productos,
        'lugares'       => $lugares,
        'filtros'       => $filtros,
        'busquedas'     => $busquedas,
        'intereses'     => $intereses,
        'e404'          => $e404,
        'salientes'     => $salientes,
        'destacados'    => $destacados,
        'sin_sesion'    => $sinSesion,
    ];
}
