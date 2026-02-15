<?php
function require_admin_token(array $config): void {
    if (PHP_SAPI === 'cli') {
        return;
    }

    $token = $config['admin']['token'] ?? '';
    if ($token === '' || $token === 'YOUR_ADMIN_TOKEN') {
        http_response_code(403);
        echo 'Admin token not configured.';
        exit;
    }

    $provided = '';
    if (!empty($_SERVER['HTTP_X_ADMIN_TOKEN'])) {
        $provided = $_SERVER['HTTP_X_ADMIN_TOKEN'];
    } elseif (!empty($_GET['token'])) {
        $provided = $_GET['token'];
    } elseif (!empty($_POST['token'])) {
        $provided = $_POST['token'];
    }

    if (!is_string($provided) || $provided === '' || !hash_equals($token, $provided)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

