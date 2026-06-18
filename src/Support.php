<?php

declare(strict_types=1);

namespace Maegc;

final class Support
{
    public static function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function text(string $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        echo $data;
    }

    public static function noContent(): void
    {
        http_response_code(204);
    }

    public static function body(): array
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') !== false) {
            $raw = file_get_contents('php://input') ?: '';
            if ($raw === '') {
                return [];
            }
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }

        return $_POST ?: [];
    }

    public static function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return is_numeric($value) ? (int) $value : null;
    }

    public static function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value === 1;
        }
        $value = strtolower(trim((string) $value));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    public static function mysqlDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $timestamp = strtotime((string) $value);
        if ($timestamp === false) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    public static function isoDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $timestamp = strtotime((string) $value);
        if ($timestamp === false) {
            return null;
        }

        return gmdate('Y-m-d\TH:i:s.000\Z', $timestamp);
    }

    public static function cleanString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    public static function publicApiBase(array $config): string
    {
        if (!empty($config['public_api_url'])) {
            return rtrim((string) $config['public_api_url'], '/');
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $scheme = $https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host;
    }

    public static function safeFilename(string $name, string $fallback = 'file', string $forcedExt = ''): string
    {
        $originalExt = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $ext = $forcedExt !== '' ? ltrim($forcedExt, '.') : ($originalExt ?: 'bin');
        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = preg_replace('/[^a-z0-9_-]+/i', '-', $base) ?: $fallback;
        $base = trim(substr($base, 0, 80), '-');
        return time() . '-' . ($base ?: $fallback) . '.' . $ext;
    }

    public static function escapePdfText(mixed $value): string
    {
        $value = str_replace(["\\", "(", ")", "\r", "\n"], ["\\\\", "\\(", "\\)", ' ', ' '], (string) ($value ?? ''));
        return mb_convert_encoding($value, 'ISO-8859-1', 'UTF-8');
    }
}
