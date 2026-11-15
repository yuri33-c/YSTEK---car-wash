<?php
namespace Adianti\Database;

use PDO;
use Exception;

class TTransaction
{
    private static $conn;

    public static function open($database = 'application')
    {
        if (empty(self::$conn)) {
            $config = require __DIR__ . '/../../../app/config/application.php';
            $db = $config['database'];
            
            $dsn = "pgsql:host={$db['host']};port={$db['port']};dbname={$db['name']};";
            if ($db['sslmode'] !== '') {
                $dsn .= "sslmode={$db['sslmode']};";
            }
            // Uma aplicação de produção deve conectar somente ao banco configurado.
            // Não existe fallback para o database "postgres": isso evita mascarar
            // erro de configuração e evita conceder privilégios desnecessários.
            self::$conn = new PDO($dsn, $db['user'], $db['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        }
        return self::$conn;
    }

    public static function get()
    {
        return self::open();
    }

    public static function close()
    {
        self::$conn = null;
    }
}
