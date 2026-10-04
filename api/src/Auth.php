<?php
declare(strict_types=1);

final class Auth
{
    public static function requireUser(): array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/Bearer\s+(\S+)/', $header, $m)) {
            throw new RuntimeException('Please sign in', 401);
        }
        $payload = Jwt::decode($m[1]);
        $id = (int) ($payload['uid'] ?? 0);
        $stmt = Database::pdo()->prepare('SELECT id, name, email, created_at FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        if (!$user) {
            throw new RuntimeException('Please sign in', 401);
        }
        return $user;
    }
}
