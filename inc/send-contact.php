<?php
/**
 * SIPCONS · handler del formulario de contacto -> envía correo a info@sipcons.com
 * Recibe POST (fetch/FormData) desde contacto.html, responde JSON {ok:bool, error?:string}
 */

declare(strict_types=1);
error_reporting(0); // no exponer warnings/paths del servidor al cliente

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

const DEST_EMAIL   = 'info@sipcons.com';
const FROM_EMAIL   = 'noreply@sipcons.com'; // buzón dedicado en cPanel para no saturar info@ con rebotes
const FROM_NAME    = 'Formulario SIPCONS';
const MAX_LEN_LARGO  = 3000;  // mensaje
const MAX_LEN_CORTO  = 200;   // nombre, telefono, email, interes
const MAX_CUERPO     = 20000; // bytes del POST completo (el formulario real pesa ~1-4 KB)

// Opciones válidas del <select name="interes"> de contacto.html. Cualquier otro
// valor se descarta, para que el asunto del correo no lleve texto libre de terceros.
const INTERESES = [
    'Básculas y plataformas', 'Puntos de venta', 'Consumibles y refacciones',
    'Calibración / mantenimiento', 'Soporte técnico', 'Otro',
];

// Límite de envíos para que nadie use el formulario para mandar correos en masa
// (cada envío genera también una confirmación al correo que se escriba).
const LIMITE_POR_IP_HORA = 5;
const LIMITE_GLOBAL_HORA = 40;

function responder(bool $ok, string $error = ''): void {
    echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'error' => $error]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    responder(false, 'Método no permitido.');
}

if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > MAX_CUERPO) {
    http_response_code(413);
    responder(false, 'El mensaje es demasiado largo.');
}

// --- Solo desde el propio sitio ------------------------------------------
// fetch() del navegador manda Origin y Sec-Fetch-Site; un script o formulario de
// otro dominio que intente usar este endpoint para enviar correos se rechaza.
function origen_valido(): bool {
    $sitio = (string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
    if ($sitio !== '' && $sitio !== 'same-origin') return false;
    $origen = (string)($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '');
    if ($origen === '') return $sitio === 'same-origin';
    $host = strtolower((string)parse_url($origen, PHP_URL_HOST));
    return $host === 'sipcons.com' || $host === 'www.sipcons.com';
}
if (!origen_valido()) {
    http_response_code(403);
    responder(false, 'Solicitud no permitida.');
}

// --- Honeypot: campo oculto que un humano nunca llena -----------------
// Si viene lleno, es un bot: respondemos "éxito" sin enviar nada.
if (!empty($_POST['website'] ?? '')) {
    responder(true);
}

/**
 * Cuenta envíos de la última hora (por IP y en total) en inc/contacto-limite.dat,
 * bloqueado al público por inc/.htaccess. La IP se guarda como hash, no en claro.
 * Devuelve false si se rebasó el límite.
 */
function dentro_del_limite(): bool {
    $fh = @fopen(__DIR__ . '/contacto-limite.dat', 'c+');
    if (!$fh) return true; // si no se puede escribir, no bloquear a clientes reales
    flock($fh, LOCK_EX);
    $datos = json_decode((string)stream_get_contents($fh), true);
    $ahora = time();
    $registros = array_values(array_filter(
        is_array($datos) ? $datos : [],
        static fn($r) => is_array($r) && isset($r['t'], $r['ip']) && $r['t'] > $ahora - 3600
    ));
    $ip = hash('sha256', 'sipcons|' . ($_SERVER['REMOTE_ADDR'] ?? ''));
    $deEstaIp = count(array_filter($registros, static fn($r) => $r['ip'] === $ip));
    $ok = $deEstaIp < LIMITE_POR_IP_HORA && count($registros) < LIMITE_GLOBAL_HORA;
    if ($ok) $registros[] = ['t' => $ahora, 'ip' => $ip];
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($registros));
    flock($fh, LOCK_UN);
    fclose($fh);
    return $ok;
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
if (!in_array($interes, INTERESES, true)) $interes = '';
$mensaje  = trim((string)($_POST['mensaje'] ?? ''));
$mensaje  = mb_substr($mensaje, 0, MAX_LEN_LARGO);

if ($nombre === '' || $telefono === '' || $email === '' || $mensaje === '') {
    responder(false, 'Faltan campos obligatorios.');
}

if (strlen($email) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    responder(false, 'El correo electrónico no es válido.');
}

$digitos = (string)preg_replace('/\D+/', '', $telefono);
if (!preg_match('/^[0-9+()\-.\s]{7,25}$/', $telefono) || strlen($digitos) < 7 || strlen($digitos) > 15) {
    responder(false, 'El teléfono no es válido.');
}

if (!dentro_del_limite()) {
    http_response_code(429);
    responder(false, 'Recibimos varios mensajes seguidos. Intenta más tarde o escríbenos por WhatsApp.');
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

$enviado = mail(DEST_EMAIL, $asunto, $cuerpo, implode("\r\n", $headers), '-f' . FROM_EMAIL);

if (!$enviado) {
    responder(false, 'No se pudo enviar el mensaje. Intenta de nuevo o escríbenos por WhatsApp.');
}

// Contador de envíos (anónimo): se omite si el navegador tiene «No rastrear» / ?notrack=1.
if (empty($_POST['nt'])) {
    require_once __DIR__ . '/eventos.php';
    sipcons_registrar_evento('formulario', [
        'p' => '/contacto.html',
        's' => $_POST['sid'] ?? '',
        'v' => $_POST['vid'] ?? '',
        'i' => $interes,
    ]);
}

// --- Confirmación automática al cliente --------------------------------
// No afecta la respuesta al navegador: el mensaje a info@ ya se envió,
// que esta confirmación falle no debe mostrarse como error al usuario.
//
// Va al correo que escribió el visitante, que podría ser el de otra persona.
// Para que nadie la use para mandar texto o enlaces a terceros desde
// noreply@sipcons.com, NO se repite el mensaje y del nombre solo se usa la
// primera palabra si son letras (sin enlaces, números ni símbolos).
$asuntoConfirma = mb_encode_mimeheader('Hemos recibido tu mensaje — SIPCONS', 'UTF-8');

$interesMostrado = $interes !== '' ? $interes : '—';
$primerNombre = (string)preg_replace('/\s.*$/su', '', $nombre);
$saludo = preg_match("/^\\p{L}[\\p{L}'\\-]{0,29}$/u", $primerNombre) ? "Hola {$primerNombre}," : 'Hola,';

$textoConfirma = "{$saludo}\n\n"
    . "Gracias por escribirnos. Ya recibimos tu mensaje y un asesor te contactará "
    . "en horario laboral con una cotización a la medida de acuerdo a tus requerimientos y necesidades.\n\n"
    . "\"Después de la venta, el servicio es lo que cuenta.\"\n\n"
    . "Tema de tu mensaje: {$interesMostrado}\n\n"
    . "Si es urgente, escríbenos por WhatsApp: https://wa.me/526641086038\n"
    . "Tel: (664) 630-0471\n"
    . "Sitio web: https://sipcons.com/\n\n"
    . "— Equipo SIPCONS\n"
    . "Soluciones Integrales de Pesaje y Control";

$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$htmlConfirma = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:0;background:#F0F3F9;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F0F3F9;padding:32px 16px;">
<tr><td align="center">
<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#FFFFFF;border-radius:12px;overflow:hidden;border:1px solid #DDE4EE;">
  <tr>
    <td align="center" style="padding:32px 24px 16px;">
      <a href="https://sipcons.com/" style="text-decoration:none;">
        <img src="https://sipcons.com/assets/img/logo-sipcons.png" width="220" alt="SIPCONS" style="display:block;max-width:220px;height:auto;border:0;">
      </a>
    </td>
  </tr>
  <tr>
    <td align="center" style="padding:0 24px 20px;">
      <p style="margin:0;font-style:italic;font-size:14px;color:#1A4BD0;font-weight:600;">Después de la venta, el servicio es lo que cuenta</p>
    </td>
  </tr>
  <tr><td style="height:4px;background:linear-gradient(90deg,#1A4BD0,#22D3EE);"></td></tr>
  <tr>
    <td style="padding:28px 32px 8px;">
      <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#0F1A2E;">{$e($saludo)}</p>
      <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#334155;">Gracias por escribirnos. Ya recibimos tu mensaje y un asesor te contactará en horario laboral con una cotización a la medida de acuerdo a tus requerimientos y necesidades.</p>
    </td>
  </tr>
  <tr>
    <td style="padding:8px 32px 24px;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F6F8FC;border-radius:8px;border:1px solid #EDF1F7;">
        <tr><td style="padding:16px 20px;font-size:14px;line-height:1.7;color:#334155;">
          <strong style="color:#0F1A2E;">Tema de tu mensaje:</strong> {$e($interesMostrado)}
        </td></tr>
      </table>
    </td>
  </tr>
  <tr>
    <td align="center" style="padding:0 32px 32px;">
      <a href="https://wa.me/526641086038" style="display:inline-block;background:#25D366;color:#FFFFFF;text-decoration:none;font-weight:700;font-size:14px;padding:12px 22px;border-radius:8px;margin:0 6px 10px;">Escribir por WhatsApp</a>
      <a href="tel:+526646300471" style="display:inline-block;background:transparent;color:#1A4BD0;text-decoration:none;font-weight:700;font-size:14px;padding:12px 22px;border-radius:8px;border:1px solid #C3CDDC;margin:0 6px 10px;">Llamar: (664) 630-0471</a>
    </td>
  </tr>
  <tr><td style="border-top:1px solid #EDF1F7;"></td></tr>
  <tr>
    <td align="center" style="padding:20px 24px 28px;">
      <p style="margin:0 0 4px;font-size:12px;color:#64748B;">SIPCONS — Soluciones Integrales de Pesaje y Control</p>
      <p style="margin:0 0 14px;font-size:12px;color:#94A2B8;">Av. De Las Perlas 630, Playas de Tijuana, B.C. · Lun–Vie 08:30–18:00 · Sáb 09:00–13:30</p>
      <a href="https://sipcons.com/" style="font-size:12px;font-weight:700;color:#1A4BD0;text-decoration:none;">Visitar sipcons.com →</a>
    </td>
  </tr>
</table>
</td></tr>
</table>
</body>
</html>
HTML;

$boundary = 'sipcons-' . bin2hex(random_bytes(12));

$cuerpoConfirma = "--{$boundary}\r\n"
    . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
    . $textoConfirma . "\r\n\r\n"
    . "--{$boundary}\r\n"
    . "Content-Type: text/html; charset=UTF-8\r\n\r\n"
    . $htmlConfirma . "\r\n\r\n"
    . "--{$boundary}--";

$headersConfirma   = [];
$headersConfirma[] = 'From: ' . mb_encode_mimeheader(FROM_NAME, 'UTF-8') . ' <' . FROM_EMAIL . '>';
$headersConfirma[] = 'Reply-To: SIPCONS <' . DEST_EMAIL . '>';
$headersConfirma[] = 'MIME-Version: 1.0';
$headersConfirma[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

mail($email, $asuntoConfirma, $cuerpoConfirma, implode("\r\n", $headersConfirma), '-f' . FROM_EMAIL);

responder(true);
