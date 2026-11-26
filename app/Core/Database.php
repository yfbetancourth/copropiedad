<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

final class Database
{
    /**
     * Modo SQL estricto en cada conexión: un valor que no cabe (texto largo, ENUM desconocido, fecha inválida) es un
     * error y no se guarda recortado o vacío con solo una advertencia. No depende de la configuración del servidor
     * (XAMPP lo trae sin STRICT_*; MariaDB de producción sí lo trae por defecto).
     */
    public const SQL_MODE = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION';

    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $host = self::requiredSetting('DB_HOST');
        $port = self::requiredSetting('DB_PORT');
        $database = self::requiredSetting('DB_DATABASE');
        $username = self::requiredSetting('DB_USERNAME');
        $password = self::requiredSetting('DB_PASSWORD');

        if (!preg_match('/^[A-Za-z0-9.-]+$/D', $host)) {
            throw new RuntimeException('DB_HOST tiene un formato no valido.');
        }

        if (!preg_match('/^[A-Za-z0-9_]+$/D', $database)) {
            throw new RuntimeException('DB_DATABASE tiene un formato no valido.');
        }

        $portNumber = filter_var($port, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);

        if ($portNumber === false) {
            throw new RuntimeException('DB_PORT debe ser un puerto valido.');
        }

        $isLocalHost = in_array(strtolower($host), ['localhost', '127.0.0.1'], true);
        $sslCa = Env::get('DB_SSL_CA', false);
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_PERSISTENT => false,
            \Pdo\Mysql::ATTR_INIT_COMMAND => "SET SESSION sql_mode = '" . self::SQL_MODE . "'",
        ];

        if (!$isLocalHost && $sslCa === '') {
            throw new RuntimeException('DB_SSL_CA es obligatorio para conexiones MySQL remotas.');
        }

        if ($sslCa !== '') {
            if (!is_file($sslCa) || !is_readable($sslCa)) {
                throw new RuntimeException('El certificado indicado en DB_SSL_CA no existe o no se puede leer.');
            }

            $options[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $host,
            $portNumber,
            $database
        );

        self::$connection = new PDO($dsn, $username, $password, $options);

        return self::$connection;
    }

    private static function requiredSetting(string $name): string
    {
        $value = Env::get($name, false);

        if ($value === '') {
            throw new RuntimeException(sprintf('%s debe configurarse en el archivo .env.', $name));
        }

        return $value;
    }

}
