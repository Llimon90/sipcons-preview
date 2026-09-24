<?php
/**
 * SIPCONS · contador de eventos de contacto (formulario, WhatsApp, teléfono).
 * Cada evento es una línea JSON en inc/eventos.log (bloqueado por inc/.htaccess
 * y fuera de git). Se lee con inc/stats.php.
 */

declare(strict_types=1);

const SIPCONS_EVENTOS_VALIDOS = ['formulario', 'whatsapp', 'telefono'];

function sipcons_registrar_evento(string $evento, string $pagina = ''): void {
    if (!in_array($evento, SIPCONS_EVENTOS_VALIDOS, true)) return;

    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua === '' || preg_match('/bot|crawl|spider|slurp|curl|wget|python|headless/i', $ua)) return;

    date_default_timezone_set('America/Tijuana');
    $pagina = substr((string)preg_replace('/[^A-Za-z0-9_\-.\/]/', '', $pagina), 0, 120);
    $linea = json_encode(['t' => date('c'), 'e' => $evento, 'p' => $pagina], JSON_UNESCAPED_SLASHES) . "\n";
    @file_put_contents(__DIR__ . '/eventos.log', $linea, FILE_APPEND | LOCK_EX);
}
