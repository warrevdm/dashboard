<?php

require_once __DIR__ . '/../app/Auth.php';

Auth::boot();
Auth::sendSecurityHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php', true, 302);
    exit;
}

$csrfToken = (string) ($_POST['csrf_token'] ?? '');

if (!Auth::verifyCsrf($csrfToken)) {
    http_response_code(400);
    die('Ongeldige afmeldpoging. Vernieuw de pagina en probeer opnieuw.');
}

Auth::logout();

header('Location: login.php?logged_out=1', true, 302);
exit;
