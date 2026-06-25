<?php

declare(strict_types=1);

namespace Maegc;

final class Cloudinary
{
    public function __construct(private array $config)
    {
    }

    public function isConfigured(): bool
    {
        $cloud = $this->config['cloudinary'] ?? [];
        return !empty($cloud['cloud_name'])
            && !empty($cloud['api_key'])
            && !empty($cloud['api_secret']);
    }

    public function upload(array $file, string $resourceType, string $folder): string
    {
        $cloud = $this->config['cloudinary'] ?? [];
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Cloudinary is not configured');
        }

        $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            throw new \RuntimeException($this->uploadErrorMessage($uploadError));
        }

        if (empty($file['tmp_name']) || !is_uploaded_file((string) $file['tmp_name'])) {
            throw new \RuntimeException('The uploaded file could not be read');
        }

        if (!in_array($resourceType, ['image', 'raw', 'video'], true)) {
            throw new \RuntimeException('Unsupported Cloudinary resource type');
        }

        $timestamp = time();
        $params = [
            'folder' => $folder,
            'timestamp' => $timestamp,
        ];
        ksort($params);
        $toSign = implode('&', array_map(
            fn ($key, $value) => $key . '=' . $value,
            array_keys($params),
            array_values($params)
        ));
        $signature = sha1($toSign . $cloud['api_secret']);

        $url = sprintf(
            'https://api.cloudinary.com/v1_1/%s/%s/upload',
            rawurlencode((string) $cloud['cloud_name']),
            rawurlencode($resourceType)
        );

        $post = $params + [
            'api_key' => $cloud['api_key'],
            'signature' => $signature,
            'file' => new \CURLFile(
                (string) $file['tmp_name'],
                (string) ($file['type'] ?: 'application/octet-stream'),
                (string) ($file['name'] ?? 'upload')
            ),
        ];

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Could not initialize the Cloudinary upload');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 60,
        ]);
        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $status < 200 || $status >= 300) {
            $json = is_string($raw) ? json_decode($raw, true) : null;
            $cloudinaryError = is_array($json) ? ($json['error']['message'] ?? null) : null;
            throw new \RuntimeException(
                $cloudinaryError
                    ? 'Cloudinary upload failed: ' . $cloudinaryError
                    : ($error ?: 'Cloudinary upload failed')
            );
        }

        $json = json_decode((string) $raw, true);
        if (!is_array($json) || empty($json['secure_url'])) {
            throw new \RuntimeException('Cloudinary did not return a public URL');
        }

        return (string) $json['secure_url'];
    }

    private function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The uploaded file is too large for the server',
            UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'The server upload directory is missing',
            UPLOAD_ERR_CANT_WRITE => 'The server could not save the uploaded file',
            UPLOAD_ERR_EXTENSION => 'A server extension stopped the upload',
            default => 'The file upload failed',
        };
    }
}
