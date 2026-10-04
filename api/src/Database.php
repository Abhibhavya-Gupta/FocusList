<?php
declare(strict_types=1);

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $url = Env::get('DATABASE_URL') ?? Env::get('MYSQL_URL');
        if ($url) {
            $parts = parse_url($url);
            $host = $parts['host'] ?? '127.0.0.1';
            $port = (string) ($parts['port'] ?? '3306');
            $user = $parts['user'] ?? 'root';
            $pass = $parts['pass'] ?? '';
            $name = ltrim($parts['path'] ?? '/dayline', '/');
        } else {
            $host = Env::get('MYSQLHOST') ?? Env::get('DB_HOST', '127.0.0.1');
            $port = Env::get('MYSQLPORT') ?? Env::get('DB_PORT', '3306');
            $name = Env::get('MYSQLDATABASE') ?? Env::get('DB_NAME', 'dayline');
            $user = Env::get('MYSQLUSER') ?? Env::get('DB_USER', 'dayline');
            $pass = Env::get('MYSQLPASSWORD') ?? Env::get('DB_PASS', 'dayline');
        }

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);
        self::$pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return self::$pdo;
    }

    public static function migrate(): void
    {
        $sqlFile = dirname(__DIR__, 2) . '/sql/schema.sql';
        if (!is_file($sqlFile)) {
            $sqlFile = __DIR__ . '/../../sql/schema.sql';
        }
        if (!is_file($sqlFile)) {
            return;
        }
        $sql = file_get_contents($sqlFile);
        if ($sql === false || $sql === '') {
            return;
        }
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            if ($statement === '' || str_starts_with($statement, '--')) {
                continue;
            }
            self::pdo()->exec($statement);
        }
    }
}
