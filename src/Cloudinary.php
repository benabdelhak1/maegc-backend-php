<?php

declare(strict_types=1);

namespace Maegc;

final class Cloudinary
{
    public function __construct(private array $config)
    {
    }

    public function upload(array $file, string $resourceType, string $folder): string
    {
        $cloud = $this->config['cloudinary'] ?? [];
        if (empty($cloud['cloud_name']) || empty($cloud['api_key']) || empty($cloud['api_secret'])) {
            throw new \RuntimeException('Cloudinary is not configured');
        }

        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new \RuntimeException('No file uploaded');
        }

        $timestamp = time();
        $params = [
            'folder' => $folder,
            'timestamp' => $timestamp,
            'type' => 'upload',
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
            'file' => new \CURLFile($file['tmp_name'], $file['type'] ?: 'application/octet-stream', $file['name'] ?? 'upload'),
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ]);
        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $status < 200 || $status >= 300) {
            throw new \RuntimeException($error ?: 'Cloudinary upload failed');
        }

        $json = json_decode((string) $raw, true);
        if (!is_array($json) || empty($json['secure_url'])) {
            throw new \RuntimeException('Cloudinary did not return a public URL');
        }

        return (string) $json['secure_url'];
    }
}
