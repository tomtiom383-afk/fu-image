<?php

declare(strict_types=1);

namespace ImageHosting;

class Response
{
    /**
     * @param mixed $data
     */
    public static function json(int $code, $data = null, string $message = ''): never
    {
        http_response_code($code >= 100 && $code < 600 ? $code : 200);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');

        echo json_encode([
            'code' => $code,
            'data' => $data,
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        exit;
    }

    public static function error(string $message, int $code = 400): never
    {
        self::json($code, null, $message);
    }

    public static function success($data = null, string $message = ''): never
    {
        self::json(200, $data, $message);
    }

    /**
     * @param mixed $data
     */
    public static function raw($data): never
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        exit;
    }
}
