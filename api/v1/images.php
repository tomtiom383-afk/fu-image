<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use ImageHosting\Response;
use ImageHosting\UploadHandler;

$method = $_SERVER['REQUEST_METHOD'];
$handler = new UploadHandler($config);

if ($method === 'GET') {
    $page = max(1, (int) input('page', 1));
    $limit = min(100, max(1, (int) input('limit', 20)));
    Response::success($handler->list($page, $limit));
}

if ($method === 'DELETE' || ($method === 'POST' && input('_method', '') === 'DELETE')) {
    $auth->requireAuth();
    $id = input('id', '');
    if (!is_string($id) || $id === '') {
        Response::error('缺少图片 ID', 400);
    }
    if ($handler->delete($id)) {
        Response::success(null, '删除成功');
    }
    Response::error('图片不存在', 404);
}

Response::error('Method Not Allowed', 405);
