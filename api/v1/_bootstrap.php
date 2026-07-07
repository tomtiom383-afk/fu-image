<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'ImageHosting\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/../../src/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) require $file;
});

use ImageHosting\Config;
use ImageHosting\Auth;

$configPath = __DIR__ . '/../../config/config.json';
$config = Config::load($configPath);

$timezone = $config->get('timezone', 'Asia/Shanghai');
date_default_timezone_set($timezone);

$auth = new Auth($config);
$auth->start();

// CORS
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    $allowedOrigins = $config->get('cors.allowed_origins', []);
    $allowed = false;
    foreach ($allowedOrigins as $allowedOrigin) {
        if ($origin === $allowedOrigin) {
            $allowed = true;
            break;
        }
    }
    if (!$allowed) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['code' => 403, 'data' => null, 'message' => '来源不被允许']);
        exit;
    }
    header('Access-Control-Allow-Origin: ' . $origin);
}
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$input = [];
$rawInput = file_get_contents('php://input');
if ($rawInput !== false && $rawInput !== '') {
    $parsed = json_decode($rawInput, true);
    if (is_array($parsed)) {
        $input = $parsed;
    }
}

/**
 * @param array<string, mixed> $sources
 * @return array<string, mixed>
 */
function getInput(array $sources = []): array
{
    global $input;
    $result = [];
    foreach (array_merge([$_GET, $_POST, $input], $sources) as $source) {
        if (is_array($source)) {
            foreach ($source as $key => $value) {
                $result[$key] = $value;
            }
        }
    }
    return $result;
}

/**
 * @param string|int $key
 * @param mixed $default
 * @return mixed
 */
function input($key, $default = null)
{
    $all = getInput();
    return $all[$key] ?? $default;
}
