<?php

// docker-entrypoint.sh equivalent in PHP
// Generates config/config.json from config/config.example.json using environment variables
// Prevents shell injection, works cross-platform

$examplePath = __DIR__ . '/config/config.example.json';
$configPath = __DIR__ . '/config/config.json';

if (!file_exists($examplePath)) {
    fwrite(STDERR, "ERROR: config.example.json not found" . PHP_EOL);
    exit(1);
}

$config = json_decode(file_get_contents($examplePath), true);
if (!is_array($config)) {
    fwrite(STDERR, "ERROR: Failed to parse config.example.json" . PHP_EOL);
    exit(1);
}

// Apply environment variable overrides
$envOverrides = [
    ['SITE_NAME', 'site.name'],
    ['SITE_URL', 'site.base_url'],
    ['ADMIN_USER', 'auth.admin_user'],
    ['ADMIN_PASSWORD_HASH', 'auth.password_hash'],
    ['API_KEY', 'auth.api_keys'],   // special handling
    ['TIMEZONE', 'timezone'],
    ['UPLOAD_MAX_SIZE', 'upload.max_size', 'int'],
    ['CONVERT_WEBP', 'upload.convert_webp', 'bool'],
    ['WEBP_QUALITY', 'upload.webp_quality', 'int'],
    ['JPEG_QUALITY', 'upload.jpeg_quality', 'int'],
    ['SESSION_LIFETIME', 'security.session_lifetime', 'int'],
    ['REMEMBER_LIFETIME', 'security.remember_lifetime', 'int'],
    ['UPLOAD_RATE_LIMIT', 'security.upload_rate_limit_per_minute', 'int'],
    ['WATERMARK_ENABLED', 'upload.watermark.enabled', 'bool'],
    ['WATERMARK_TEXT', 'upload.watermark.text'],
    ['THUMBNAIL_ENABLED', 'upload.thumbnail.enabled', 'bool'],
];

function setNestedKey(array &$arr, string $path, $value): void
{
    $keys = explode('.', $path);
    $current = &$arr;
    foreach ($keys as $k) {
        if (!isset($current[$k]) || !is_array($current[$k])) {
            $current[$k] = [];
        }
        $current = &$current[$k];
    }
    $current = $value;
}

foreach ($envOverrides as $override) {
    $envName = $override[0];
    $envKey = $override[1];
    $type = $override[2] ?? 'string';

    $envValue = getenv($envName);
    if ($envValue === false || $envValue === '') {
        continue;
    }

    switch ($type) {
        case 'int':
            $value = (int) $envValue;
            break;
        case 'bool':
            $value = filter_var($envValue, FILTER_VALIDATE_BOOLEAN);
            break;
        default:
            $value = $envValue;
    }

    if ($envKey === 'auth.api_keys') {
        // Special: API_KEY env var sets a key-value pair in auth.api_keys
        $keyName = $envValue;
        $config['auth']['api_keys'] = [
            $keyName => ['name' => 'default', 'expires' => 0],
        ];
        // Also remove placeholder key if it exists
        if (isset($config['auth']['api_keys']['YOUR_API_KEY_HERE'])) {
            unset($config['auth']['api_keys']['YOUR_API_KEY_HERE']);
        }
    } else {
        setNestedKey($config, $envKey, $value);
    }
}

// Ensure CORS allowed_origins includes SITE_URL
$siteUrl = getenv('SITE_URL') ?: ($config['site']['base_url'] ?? '');
if ($siteUrl && !in_array($siteUrl, $config['cors']['allowed_origins'] ?? [])) {
    $config['cors']['allowed_origins'][] = $siteUrl;
}

$json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    fwrite(STDERR, "ERROR: Failed to encode config JSON" . PHP_EOL);
    exit(1);
}

if (file_put_contents($configPath, $json, LOCK_EX) === false) {
    fwrite(STDERR, "ERROR: Failed to write config.json" . PHP_EOL);
    exit(1);
}

echo "✓ config.json generated from environment" . PHP_EOL;
