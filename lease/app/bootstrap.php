<?php

require_once __DIR__ . '/Auth.php';

Auth::requireLogin();
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($requestMethod, ['GET', 'HEAD', 'POST'], true)) {
    header('Allow: GET, HEAD, POST');
    http_response_code(405);
    exit('Deze verzoekmethode wordt niet ondersteund.');
}
if ($requestMethod === 'POST') {
    Auth::requirePost();
}
