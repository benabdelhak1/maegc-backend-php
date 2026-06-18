<?php

declare(strict_types=1);

namespace Maegc;

final class Auth
{
    public function __construct(private array $config)
    {
    }

    public function user(): ?array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($header, 7));
        $payload = Jwt::decode($token, (string) $this->config['jwt_secret']);
        if (!$payload) {
            return null;
        }

        return [
            'id' => (int) ($payload['id'] ?? 0),
            'role' => (string) ($payload['role'] ?? ''),
            'teamId' => isset($payload['teamId']) ? (int) $payload['teamId'] : null,
        ];
    }

    public function requireUser(): array
    {
        $user = $this->user();
        if (!$user || !$user['id']) {
            Support::json(['message' => 'No token provided'], 401);
            exit;
        }
        return $user;
    }

    public function requireAdmin(): array
    {
        $user = $this->requireUser();
        if (!in_array($user['role'], ['ADMIN', 'SUPERADMIN'], true)) {
            Support::json(['error' => 'Admin access only'], 403);
            exit;
        }
        return $user;
    }

    public function requireSuperAdmin(): array
    {
        $user = $this->requireUser();
        if ($user['role'] !== 'SUPERADMIN') {
            Support::json(['message' => 'SUPERADMIN only'], 403);
            exit;
        }
        return $user;
    }
}
