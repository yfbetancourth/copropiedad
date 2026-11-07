<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

final class Database
{
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
        $sslCa = self::setting('DB_SSL_CA');
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_PERSISTENT => false,
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
        $value = self::setting($name);

        if ($value === '') {
            throw new RuntimeException(sprintf('%s debe configurarse en el archivo .env.', $name));
        }

        return $value;
    }

    private static function setting(string $name): string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) ? $value : '';
    }
}
