<?php
/**
 * SIPCONS · recibe por POST (sendBeacon desde site.js) los clics en botones de
 * WhatsApp y teléfono y los suma al contador. No responde contenido.
 */

declare(strict_types=1);
error_reporting(0);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require __DIR__ . '/eventos.php';
    sipcons_registrar_evento((string)($_POST['evento'] ?? ''), (string)($_POST['pagina'] ?? ''));
}
http_response_code(204);
