<?php
/**
 * SIPCONS · handler del formulario de contacto -> envía correo a info@sipcons.com
 * Recibe POST (fetch/FormData) desde contacto.html, responde JSON {ok:bool, error?:string}
 */

declare(strict_types=1);
error_reporting(0); // no exponer warnings/paths del servidor al cliente

header('Content-Type: application/json; charset=UTF-8');

const DEST_EMAIL   = 'info@sipcons.com';
const FROM_EMAIL   = 'noreply@sipcons.com'; // buzón dedicado en cPanel para no saturar info@ con rebotes
const FROM_NAME    = 'Formulario SIPCONS';
const MAX_LEN_LARGO  = 3000; // mensaje
const MAX_LEN_CORTO  = 200;  // nombre, telefono, email, interes

function responder(bool $ok, string $error = ''): void {
    echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'error' => $error]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    responder(false, 'Método no permitido.');
}

// --- Honeypot: campo oculto que un humano nunca llena -----------------
// Si viene lleno, es un bot: respondemos "éxito" sin enviar nada.
if (!empty($_POST['website'] ?? '')) {
    responder(true);
}

function campo(string $clave, int $maxLen): string {
    $valor = trim((string)($_POST[$clave] ?? ''));
    $valor = preg_replace('/[\r\n]+/', ' ', $valor); // evita inyección de cabeceras de correo
    return mb_substr($valor, 0, $maxLen);
}

$nombre   = campo('nombre', MAX_LEN_CORTO);
$telefono = campo('telefono', MAX_LEN_CORTO);
$email    = campo('email', MAX_LEN_CORTO);
$interes  = campo('interes', MAX_LEN_CORTO);
$mensaje  = trim((string)($_POST['mensaje'] ?? ''));
$mensaje  = mb_substr($mensaje, 0, MAX_LEN_LARGO);

if ($nombre === '' || $telefono === '' || $email === '' || $mensaje === '') {
    responder(false, 'Faltan campos obligatorios.');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    responder(false, 'El correo electrónico no es válido.');
}

$asunto = mb_encode_mimeheader('Nuevo contacto desde sipcons.com' . ($interes !== '' ? " — {$interes}" : ''), 'UTF-8');

$cuerpo = "Se recibió un mensaje desde el formulario de contacto de sipcons.com:\n\n"
    . "Nombre:    {$nombre}\n"
    . "Teléfono:  {$telefono}\n"
    . "Correo:    {$email}\n"
    . "Interés:   " . ($interes !== '' ? $interes : '—') . "\n\n"
    . "Mensaje:\n{$mensaje}\n\n"
    . "---\n"
    . 'IP: ' . ($_SERVER['REMOTE_ADDR'] ?? '—') . "\n"
    . 'Fecha: ' . date('Y-m-d H:i:s');

$headers   = [];
$headers[] = 'From: ' . mb_encode_mimeheader(FROM_NAME, 'UTF-8') . ' <' . FROM_EMAIL . '>';
$headers[] = 'Reply-To: ' . mb_encode_mimeheader($nombre, 'UTF-8') . ' <' . $email . '>';
$headers[] = 'Content-Type: text/plain; charset=UTF-8';
$headers[] = 'X-Mailer: PHP/' . phpversion();

$enviado = mail(DEST_EMAIL, $asunto, $cuerpo, implode("\r\n", $headers), '-f' . FROM_EMAIL);

if (!$enviado) {
    responder(false, 'No se pudo enviar el mensaje. Intenta de nuevo o escríbenos por WhatsApp.');
}

responder(true);
