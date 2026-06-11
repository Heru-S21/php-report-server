<?php

namespace ReportingEngine\Core;

class Auth
{
    private static array $config = [];
    private static array $bypassPatterns = [
        '#^/login$#',
        '#^/api/auth/login$#',
        '#^/css/#',
        '#^/js/#',
        '#^/img/#',
        '#^/api/render/#',
    ];

    public static function init(array $config): void
    {
        self::$config = $config;
    }

    public static function isEnabled(): bool
    {
        return !empty(self::$config['enabled']);
    }

    public static function getSecret(): string
    {
        $key = Database::getAppKey();
        if ($key === '') {
            $key = self::$config['password'] ?? 'default-dev-key';
        }
        return $key;
    }

    public static function generateToken(string $username): string
    {
        $payload = self::base64UrlEncode(json_encode([
            'user' => $username,
            'exp' => time() + 86400,
            'iat' => time(),
        ]));
        $sig = self::base64UrlEncode(
            hash_hmac('sha256', $payload, self::getSecret(), true)
        );
        return $payload . '.' . $sig;
    }

    public static function validateToken(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) return null;

        $expectedSig = self::base64UrlEncode(
            hash_hmac('sha256', $parts[0], self::getSecret(), true)
        );
        if (!hash_equals($expectedSig, $parts[1])) return null;

        $payload = json_decode(self::base64UrlDecode($parts[0]), true);
        if (!$payload || !isset($payload['exp']) || $payload['exp'] < time()) return null;

        return $payload;
    }

    public static function authenticate(string $username, string $password): ?string
    {
        $cfgUser = self::$config['username'] ?? '';
        $cfgPass = self::$config['password'] ?? '';

        if ($cfgUser === '' || $cfgPass === '') return null;
        if ($username !== $cfgUser) return null;
        if (!hash_equals($cfgPass, $password)) return null;

        return self::generateToken($username);
    }

    public static function getTokenFromRequest(Request $request): ?string
    {
        $header = $request->headers['Authorization'] ?? $request->headers['authorization'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return $m[1];
        }
        return $request->cookies['auth_token'] ?? null;
    }

    public static function getCurrentUser(Request $request): ?string
    {
        $token = self::getTokenFromRequest($request);
        if (!$token) return null;
        $payload = self::validateToken($token);
        return $payload['user'] ?? null;
    }

    public static function isBypassRoute(string $uri): bool
    {
        foreach (self::$bypassPatterns as $pattern) {
            if (preg_match($pattern, $uri)) return true;
        }
        return false;
    }

    public static function middleware(Request $request): ?Response
    {
        if (!self::isEnabled()) return null;
        if (self::isBypassRoute($request->uri)) return null;

        $user = self::getCurrentUser($request);
        if ($user !== null) return null;

        $isApi = str_starts_with($request->uri, '/api/');
        if ($isApi) {
            return Response::error('Authentication required', 401);
        }

        return new Response('', 302, ['Location' => '/login']);
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
