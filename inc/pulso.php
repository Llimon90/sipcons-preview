<?php
/**
 * SIPCONS · recibe por POST (sendBeacon desde site.js; nombre neutro a propósito,
 * porque los bloqueadores de anuncios suelen bloquear rutas llamadas «track») los eventos de
 * comportamiento y los guarda. No responde contenido.
 * Campo "d": JSON de un evento {"e":"vista","p":"/",...} o un arreglo de ellos.
 */

declare(strict_types=1);
error_reporting(0);

function sipcons_origen_valido(): bool {
    if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'same-origin') return true;
    $origen = (string)($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '');
    if ($origen === '') return false;
    $host = strtolower((string)parse_url($origen, PHP_URL_HOST));
    return $host === 'sipcons.com' || $host === 'www.sipcons.com';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && sipcons_origen_valido()) {
    $crudo = (string)($_POST['d'] ?? '');
    if ($crudo !== '' && strlen($crudo) <= 8192) {
        $datos = json_decode($crudo, true);
        if (is_array($datos)) {
            require __DIR__ . '/eventos.php';
            $lista = isset($datos['e']) ? [$datos] : array_slice($datos, 0, 10);
            foreach ($lista as $ev) {
                if (is_array($ev) && isset($ev['e'])) {
                    sipcons_registrar_evento((string)$ev['e'], $ev);
                }
            }
        }
    }
}
http_response_code(204);
