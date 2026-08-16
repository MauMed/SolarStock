<?php
namespace Core;

class Response
{
    public static function json($data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Api-Key');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function error(string $msg, int $status = 400, $extra = null): void
    {
        self::json(['ok' => false, 'error' => $msg, 'detalle' => $extra], $status);
    }

    public static function ok($data = null): void
    {
        self::json(['ok' => true, 'data' => $data]);
    }
}
