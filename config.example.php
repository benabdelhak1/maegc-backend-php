<?php

return [
    // Copy this file to config.php and fill the real LWS MySQL credentials.
    'db' => [
        'host' => getenv('DB_HOST') ?: 'localhost',
        'port' => getenv('DB_PORT') ?: '3306',
        'database' => getenv('DB_DATABASE') ?: 'maegc',
        'user' => getenv('DB_USER') ?: 'maegc_user',
        'password' => getenv('DB_PASSWORD') ?: '',
        'charset' => 'utf8mb4',
    ],

    // Use a long random secret. If you keep the old JWT secret, old tokens may remain valid.
    'jwt_secret' => getenv('JWT_SECRET') ?: 'CHANGE_ME_TO_A_LONG_RANDOM_SECRET',
    'jwt_ttl_seconds' => 7 * 24 * 60 * 60,

    // Comma-separated env override is also supported: FRONTEND_ORIGINS=https://x.vercel.app,http://localhost:5173
    'frontend_origins' => [
        'https://maegc-frontend.vercel.app',
        'http://localhost:5173',
        'http://127.0.0.1:5173',
    ],

    // Public URL of this PHP API, for generated upload links. Example: https://api.your-domain.com
    'public_api_url' => getenv('PUBLIC_API_URL') ?: '',

    'cloudinary' => [
        'cloud_name' => getenv('CLOUDINARY_CLOUD_NAME') ?: '',
        'api_key' => getenv('CLOUDINARY_API_KEY') ?: '',
        'api_secret' => getenv('CLOUDINARY_API_SECRET') ?: '',
    ],

    'default_superadmin' => [
        'email' => getenv('DEFAULT_SUPERADMIN_EMAIL') ?: 'admin@maegc.com',
        'password' => getenv('DEFAULT_SUPERADMIN_PASSWORD') ?: 'admin123',
    ],

    'timezone' => 'UTC',
];
