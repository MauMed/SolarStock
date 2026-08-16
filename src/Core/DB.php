<?php
namespace Core;

use PDO;

class DB
{
    private static ?PDO $pdo = null;

    public static function get(): PDO
    {
        if (self::$pdo === null) {
            $c = (require dirname(__DIR__, 2) . '/config/config.php')['db'];
            $dsn = "mysql:host={$c['host']};dbname={$c['name']};charset={$c['charset']}";
            self::$pdo = new PDO($dsn, $c['user'], $c['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }
        return self::$pdo;
    }

    public static function q(string $sql, array $params = []): \PDOStatement
    {
        $st = self::get()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public static function insertId(): int { return (int) self::get()->lastInsertId(); }
    public static function tx(callable $fn) {
        $pdo = self::get();
        $pdo->beginTransaction();
        try { $r = $fn($pdo); $pdo->commit(); return $r; }
        catch (\Throwable $e) { $pdo->rollBack(); throw $e; }
    }
}
