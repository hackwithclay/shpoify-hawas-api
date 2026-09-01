<?php

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($path === '/preorderendpoint.php') {
    require __DIR__ . '/preorderendpoint.php';
    exit;
}

require __DIR__ . '/index.php';
