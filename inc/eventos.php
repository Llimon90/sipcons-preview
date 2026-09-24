<?php
/**
 * SIPCONS · registro de eventos de comportamiento (analítica propia, anónima).
 *
 * Cada evento es una línea JSON en inc/eventos-AAAA-MM.log (un archivo por mes,
 * fuera de git y bloqueado por inc/.htaccess). Se lee con inc/analitica.php y se
 * muestra en inc/stats.php.
 *
 * Privacidad: NO se guarda IP, ni nombre, ni correo, ni teléfono. Los
 * identificadores de visitante/sesión son cadenas aleatorias generadas en el
 * navegador. El navegador/dispositivo se resume a una familia ("Chrome",
 * "Móvil") a partir del user-agent, que no se guarda completo.
 */

declare(strict_types=1);

const SIPCONS_EVENTOS = [
    'vista', 'salida', 'whatsapp', 'telefono', 'correo', 'formulario', 'form_inicio',
    'filtro', 'busqueda', 'pdf', 'galeria', 'saliente', 'destacado', 'error404',
];

function sipcons_es_bot(string $ua): bool {
    return $ua === '' || (bool)preg_match(
        '/bot|crawl|spider|slurp|curl|wget|python|java\/|httpclient|headless|lighthouse|pingdom|monitor|preview|facebookexternalhit|scanner/i',
        $ua
    );
}

/** @return array{d:string,b:string,o:string} dispositivo, navegador y sistema operativo. */
function sipcons_perfil_ua(string $ua): array {
    $dispositivo = 'Escritorio';
    if (preg_match('/iPad|Tablet|Kindle|Silk|PlayBook/i', $ua) || (stripos($ua, 'Android') !== false && stripos($ua, 'Mobile') === false)) {
        $dispositivo = 'Tableta';
    } elseif (preg_match('/Mobi|iPhone|iPod|Android|Windows Phone/i', $ua)) {
        $dispositivo = 'Móvil';
    }

    if (preg_match('/FBAN|FBAV|FB_IAB/i', $ua))          $navegador = 'Facebook (in-app)';
    elseif (stripos($ua, 'Instagram') !== false)          $navegador = 'Instagram (in-app)';
    elseif (preg_match('/EdgA?\/|Edg\//', $ua))           $navegador = 'Edge';
    elseif (preg_match('/OPR\/|Opera/', $ua))             $navegador = 'Opera';
    elseif (stripos($ua, 'SamsungBrowser') !== false)     $navegador = 'Samsung Internet';
    elseif (preg_match('/Firefox|FxiOS/', $ua))           $navegador = 'Firefox';
    elseif (preg_match('/Chrome|CriOS/', $ua))            $navegador = 'Chrome';
    elseif (stripos($ua, 'Safari') !== false)             $navegador = 'Safari';
    else                                                  $navegador = 'Otro';

    if (preg_match('/Android/i', $ua))                    $so = 'Android';
    elseif (preg_match('/iPhone|iPad|iPod|iOS/i', $ua))   $so = 'iOS';
    elseif (preg_match('/Windows/i', $ua))                $so = 'Windows';
    elseif (preg_match('/Mac OS X|Macintosh/i', $ua))     $so = 'macOS';
    elseif (preg_match('/Linux|X11/i', $ua))              $so = 'Linux';
    else                                                  $so = 'Otro';

    return ['d' => $dispositivo, 'b' => $navegador, 'o' => $so];
}

function sipcons_limpiar_texto($valor, int $max): string {
    $valor = trim((string)$valor);
    $valor = (string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $valor);
    return mb_substr($valor, 0, $max);
}

function sipcons_limpiar_id($valor): string {
    return substr((string)preg_replace('/[^A-Za-z0-9]/', '', (string)$valor), 0, 40);
}

/**
 * @param array<string,mixed> $d Datos del evento. Claves aceptadas:
 *   p página · s sesión · v visitante · n visitante nuevo (0/1) · r sitio de origen
 *   us/um/uc utm source/medium/campaign · l idioma · ms tiempo activo (ms)
 *   sc scroll máx. (%) · lg lugar/etiqueta · pr producto · q texto (búsqueda/valor)
 *   rs resultados · i interés (formulario)
 */
function sipcons_registrar_evento(string $evento, array $d = []): void {
    if (!in_array($evento, SIPCONS_EVENTOS, true)) return;

    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (sipcons_es_bot($ua)) return;

    date_default_timezone_set('America/Tijuana');
    $perfil = sipcons_perfil_ua($ua);

    $pagina = substr((string)preg_replace('/[^A-Za-z0-9_\-.\/?=%]/', '', (string)($d['p'] ?? '')), 0, 140);

    $linea = [
        't' => date('c'),
        'e' => $evento,
        'p' => $pagina,
        's' => sipcons_limpiar_id($d['s'] ?? ''),
        'v' => sipcons_limpiar_id($d['v'] ?? ''),
        'd' => $perfil['d'], 'b' => $perfil['b'], 'o' => $perfil['o'],
    ];
    if (!empty($d['n']))  $linea['n']  = 1;
    if (!empty($d['r']))  $linea['r']  = strtolower(sipcons_limpiar_texto($d['r'], 80));
    foreach (['us', 'um', 'uc'] as $k) {
        if (!empty($d[$k])) $linea[$k] = strtolower(sipcons_limpiar_texto($d[$k], 60));
    }
    if (!empty($d['l']))  $linea['l']  = strtolower(substr(sipcons_limpiar_texto($d['l'], 10), 0, 5));
    if (isset($d['ms']))  $linea['ms'] = max(0, min(1800000, (int)$d['ms']));
    if (isset($d['sc']))  $linea['sc'] = max(0, min(100, (int)$d['sc']));
    if (isset($d['rs']))  $linea['rs'] = max(0, min(9999, (int)$d['rs']));
    foreach (['lg' => 60, 'pr' => 120, 'q' => 80, 'i' => 80] as $k => $max) {
        if (isset($d[$k]) && $d[$k] !== '') $linea[$k] = sipcons_limpiar_texto($d[$k], $max);
    }

    $archivo = __DIR__ . '/eventos-' . date('Y-m') . '.log';
    @file_put_contents($archivo, json_encode($linea, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}
