<?php
declare(strict_types=1);

final class AuthController
{
    public static function register(array $body): never
    {
        $name = trim((string) ($body['name'] ?? ''));
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');

        if (strlen($name) < 2 || strlen($name) > 80) {
            Response::error('Name must be between 2 and 80 characters');
        }
        if (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Please enter a valid email');
        }
        if (strlen($password) < 6 || strlen($password) > 72) {
            Response::error('Password must be between 6 and 72 characters');
        }

        $pdo = Database::pdo();
        $exists = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $exists->execute([$email]);
        if ($exists->fetch()) {
            Response::error('An account with that email already exists', 409);
        }

        $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)');
        $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
        $id = (int) $pdo->lastInsertId();
        $user = ['id' => $id, 'name' => $name, 'email' => $email];
        Response::json(['token' => Jwt::encode(['uid' => $id]), 'user' => $user], 201);
    }

    public static function login(array $body): never
    {
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');
        if (strlen($email) > 190 || strlen($password) > 72) {
            Response::error('Email or password is incorrect', 401);
        }
        $stmt = Database::pdo()->prepare('SELECT id, name, email, password_hash FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($password, $row['password_hash'])) {
            Response::error('Email or password is incorrect', 401);
        }
        unset($row['password_hash']);
        Response::json(['token' => Jwt::encode(['uid' => (int) $row['id']]), 'user' => $row]);
    }

    public static function me(): never
    {
        Response::json(['user' => Auth::requireUser()]);
    }
}
