<?php
/**
 * SIPCONS · conexión de solo lectura a la base de datos de WordPress/WooCommerce.
 * Usada por productos.php y por el diagnóstico inc/db-inspect.php.
 */

declare(strict_types=1);

function sipcons_db(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $configPath = __DIR__ . '/db-config.php';
    if (!is_file($configPath)) {
        throw new RuntimeException('Falta inc/db-config.php (copia inc/db-config.example.php y llena tus datos).');
    }

    $config = require $configPath;

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        $config['host'],
        $config['database']
    );

    $pdo = new PDO($dsn, $config['user'], $config['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}

function sipcons_tabla(string $nombre): string {
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/db-config.php';
    }
    return $config['prefix'] . $nombre;
}
