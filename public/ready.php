<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
try {
    db()->query('SELECT 1');
    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'ready';
} catch (Throwable $e) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'not ready';
}
