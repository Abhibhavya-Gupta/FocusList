<?php
declare(strict_types=1);

final class Jwt
{
    public static function encode(array $payload, int $ttlSeconds = 60 * 60 * 24 * 14): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload['iat'] = time();
        $payload['exp'] = time() + $ttlSeconds;
        $segments = [
            self::b64(json_encode($header, JSON_THROW_ON_ERROR)),
            self::b64(json_encode($payload, JSON_THROW_ON_ERROR)),
        ];
        $signing = implode('.', $segments);
        $segments[] = self::b64(hash_hmac('sha256', $signing, self::secret(), true));
        return implode('.', $segments);
    }

    public static function decode(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new RuntimeException('Invalid token', 401);
        }
        [$h, $p, $s] = $parts;
        $expected = self::b64(hash_hmac('sha256', $h . '.' . $p, self::secret(), true));
        if (!hash_equals($expected, $s)) {
            throw new RuntimeException('Invalid token', 401);
        }
        $payload = json_decode(self::unb64($p), true);
        if (!is_array($payload) || ($payload['exp'] ?? 0) < time()) {
            throw new RuntimeException('Token expired', 401);
        }
        return $payload;
    }

    private static function secret(): string
    {
        $secret = Env::get('JWT_SECRET');
        if ($secret === null || strlen($secret) < 32) {
            throw new RuntimeException('JWT_SECRET must contain at least 32 characters');
        }
        return $secret;
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function unb64(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        return $decoded === false ? '' : $decoded;
    }
}
