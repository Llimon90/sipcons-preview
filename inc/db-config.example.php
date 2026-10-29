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

    // Contraseña para ver el reporte de contactos (inc/stats.php?token=...):
    // envíos del formulario y clics a WhatsApp/teléfono. Larga y única.
    'stats_token' => 'CAMBIA-ESTO-POR-OTRO-TOKEN-LARGO-Y-UNICO',
];
