<?php
/**
 * SIPCONS · plantilla de configuración de la base de datos de WordPress/WooCommerce.
 *
 * Copia este archivo como "db-config.php" (mismo folder) y llena los datos reales
 * directamente en el servidor por FTP/Administrador de archivos de cPanel.
 * db-config.php está en .gitignore a propósito: nunca debe subirse al repositorio.
 *
 * Usa un usuario de MySQL de SOLO LECTURA (cPanel → Bases de datos MySQL → crear
 * usuario nuevo con privilegio SELECT únicamente sobre sipcons1_basedatos). No
 * reutilices el usuario de WordPress, que normalmente tiene permisos completos.
 */

return [
    'host'     => 'localhost',
    'database' => 'sipcons1_basedatos',
    'user'     => 'sipcons1_XXXXX',   // el usuario de solo lectura que crees en cPanel
    'password' => 'XXXXXXXXXXXX',     // la contraseña de ese usuario
    'prefix'   => 'wpwa_',            // prefijo de tablas de WordPress en esta instalación

    // Token temporal para poder abrir inc/db-inspect.php desde el navegador.
    // Invéntate algo largo y random. Borra inc/db-inspect.php del servidor
    // en cuanto termines de usarlo.
    'inspect_token' => 'CAMBIA-ESTO-POR-ALGO-LARGO-Y-UNICO',
];
