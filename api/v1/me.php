<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use ImageHosting\Response;
use ImageHosting\UploadHandler;

$handler = new UploadHandler($config);
$authorized = $auth->isAuthorized();

Response::success([
    'user' => $auth->currentUser(),
    'level' => $authorized ? 1 : 0,
    'daily_uploads' => $handler->stats()['daily_uploads'],
    'daily_limit' => 0,
    'total_images' => $handler->stats()['total_images'],
    'total_size' => $handler->stats()['total_size'],
    'api_key' => $authorized ? $auth->getValidApiKey() : '',
]);
