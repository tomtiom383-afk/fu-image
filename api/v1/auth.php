<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use ImageHosting\Response;

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $action = input('action', '');

    if ($action === 'logout') {
        $auth->logout();
        Response::success(null, '已退出登录');
    }

    $user = input('user', '');
    $password = input('password', '');
    $remember = (bool) input('remember', false);

    if (!is_string($user) || !is_string($password)) {
        Response::error('参数错误', 400);
    }

    if ($auth->login($user, $password, $remember)) {
        Response::success([
            'user' => $user,
            'level' => 1,
        ], '登录成功');
    }

    Response::error('账号或密码错误', 401);
}

if ($method === 'GET') {
    Response::success([
        'logged_in' => $auth->isAuthorized(),
        'user' => $auth->currentUser(),
        'level' => $auth->isAuthorized() ? 1 : 0,
    ]);
}

Response::error('Method Not Allowed', 405);
