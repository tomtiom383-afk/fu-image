<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use ImageHosting\Response;
use ImageHosting\UploadHandler;
use ImageHosting\Security;

$method = $_SERVER['REQUEST_METHOD'];
$handler = new UploadHandler($config);

if ($method === 'POST') {
    if (!$auth->isAuthorized()) {
        Response::error('未授权，请先登录或使用有效 API Token', 401);
    }

    $maxPerMinute = (int) $config->get('security.upload_rate_limit_per_minute', 60);
    if ($maxPerMinute > 0) {
        $ip = Security::clientIp();
        $rateFile = sys_get_temp_dir() . '/uplimit_' . md5($ip) . '.json';
        $rateData = ['c' => 0, 'r' => time() + 60];
        if (file_exists($rateFile)) {
            $read = json_decode(file_get_contents($rateFile), true);
            if (is_array($read)) $rateData = $read;
        }
        if ($rateData['r'] <= time()) {
            $rateData = ['c' => 0, 'r' => time() + 60];
        }
        $rateData['c']++;
        if ($rateData['c'] > $maxPerMinute) {
            Response::error('上传频率过高，请稍后再试', 429);
        }
        file_put_contents($rateFile, json_encode($rateData), LOCK_EX);
    }

    $file = $_FILES['file'] ?? $_FILES['image'] ?? null;
    if ($file === null || !is_array($file)) {
        Response::error('没有选择上传的文件', 400);
    }

    $convertTo = input('convert', '');
    $convertTo = is_string($convertTo) ? $convertTo : '';

    try {
        $record = $handler->handle($file, $convertTo === '' ? null : $convertTo);
        Response::success($record, '上传成功');
    } catch (\Throwable $e) {
        Response::error($e->getMessage(), 400);
    }
}

Response::error('Method Not Allowed', 405);
