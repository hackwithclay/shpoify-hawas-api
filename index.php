<?php

declare(strict_types=1);

function env_value(string $key, string $default = ''): string
{
    return getenv($key) !== false ? (string) getenv($key) : $default;
}

function json_response(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body);
    exit;
}

function cors(): void
{
    $allowedOrigin = env_value('ALLOWED_ORIGIN');
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

    if ($allowedOrigin !== '' && ($origin === $allowedOrigin || $allowedOrigin === '*')) {
        header('Access-Control-Allow-Origin: ' . $allowedOrigin);
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function is_valid_lead(array $data): bool
{
    $name = trim((string) ($data['name'] ?? ''));
    $phone = trim((string) ($data['phone'] ?? $data['phoneNumber'] ?? ''));
    $email = trim((string) ($data['email'] ?? ''));
    $message = trim((string) ($data['message'] ?? ''));
    $quantity = trim((string) ($data['quantity'] ?? ''));
    $validQuantity = $quantity === '' || preg_match('/^\d+$/', $quantity);

    return strlen($name) >= 3
        && preg_match('/^[A-Za-z ]+$/', $name)
        && preg_match('/^\d{7,15}$/', $phone)
        && filter_var($email, FILTER_VALIDATE_EMAIL)
        && strlen($message) <= 500
        && $validQuantity;
}

function split_name(string $name): array
{
    $parts = preg_split('/\s+/', trim($name));

    if (count($parts) === 1) {
        return ['', $parts[0]];
    }

    $lastName = array_pop($parts);
    return [implode(' ', $parts), $lastName];
}

function get_zoho_token(): string
{
    $url = rtrim(env_value('ZOHO_ACCOUNTS_DOMAIN', 'https://accounts.zoho.com'), '/') . '/oauth/v2/token';
    $postFields = http_build_query([
        'refresh_token' => env_value('ZOHO_REFRESH_TOKEN'),
        'client_id' => env_value('ZOHO_CLIENT_ID'),
        'client_secret' => env_value('ZOHO_CLIENT_SECRET'),
        'grant_type' => 'refresh_token',
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_RETURNTRANSFER => true,
    ]);

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $body = json_decode((string) $response, true);

    if ($status < 200 || $status >= 300 || empty($body['access_token'])) {
        error_log('Zoho token failed. Status: ' . $status . ' Response: ' . (string) $response);
        json_response(500, ['ok' => false, 'message' => 'Zoho CRM is not configured correctly.']);
    }

    return $body['access_token'];
}

function send_to_zoho(array $lead): void
{
    if (env_value('ZOHO_ENABLED', 'true') !== 'true') {
        return;
    }

    foreach (['ZOHO_CLIENT_ID', 'ZOHO_CLIENT_SECRET', 'ZOHO_REFRESH_TOKEN'] as $key) {
        if (env_value($key) === '') {
            json_response(500, ['ok' => false, 'message' => 'Zoho CRM is not configured correctly.']);
        }
    }

    [$firstName, $lastName] = split_name($lead['name']);

    $userId = strtolower($lead['email']);
    if (env_value('ZOHO_USER_ID_SOURCE', 'email') === 'phone') {
        $userId = preg_replace('/\D+/', '', $lead['country_code'] . $lead['phone']);
    }

    $userIdField = env_value('ZOHO_FIELD_USER_ID', 'User_ID');

    $record = [
        $userIdField => $userId,
        env_value('ZOHO_FIELD_FIRST_NAME', 'First_Name') => $firstName,
        env_value('ZOHO_FIELD_LAST_NAME', 'Last_Name') => $lastName,
        env_value('ZOHO_FIELD_COMPANY', 'Company') => $lead['company'],
        env_value('ZOHO_FIELD_PHONE', 'Phone') => $lead['country_code'] . $lead['phone'],
        env_value('ZOHO_FIELD_EMAIL', 'Email') => $lead['email'],
        env_value('ZOHO_FIELD_LEAD_SOURCE', 'Lead_Source') => env_value('ZOHO_LEAD_SOURCE', 'Shopify'),
        env_value('ZOHO_FIELD_SERVICE', 'Service') => $lead['service'],
        env_value('ZOHO_FIELD_MESSAGE', 'Description') => $lead['message'],
    ];

    if ($lead['customer_type'] !== '') {
        $record[env_value('ZOHO_FIELD_CUSTOMER_TYPE', 'Customer_Type')] = $lead['customer_type'];
    }

    if ($lead['product'] !== '') {
        $record[env_value('ZOHO_FIELD_PRODUCT', 'Product')] = $lead['product'];
    }

    if ($lead['quantity'] !== '') {
        $record[env_value('ZOHO_FIELD_QUANTITY', 'Quantity')] = $lead['quantity'];
    }

    $payload = [
        'data' => [$record],
        'duplicate_check_fields' => array_map('trim', explode(',', env_value('ZOHO_DUPLICATE_CHECK_FIELDS', $userIdField))),
    ];

    $url = rtrim(env_value('ZOHO_API_DOMAIN', 'https://www.zohoapis.com'), '/')
        . '/crm/v8/'
        . rawurlencode(env_value('ZOHO_MODULE', 'Leads'))
        . '/upsert';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Zoho-oauthtoken ' . get_zoho_token(),
            'Content-Type: application/json',
        ],
    ]);

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status < 200 || $status >= 300) {
        error_log('Zoho lead failed. Status: ' . $status . ' Response: ' . (string) $response);
        json_response(502, ['ok' => false, 'message' => 'Zoho CRM rejected the lead request.']);
    }
}

cors();

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/health') {
    json_response(200, ['ok' => true]);
}

if ($path !== '/api/leads') {
    json_response(404, ['ok' => false, 'message' => 'Not found.']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, ['ok' => false, 'message' => 'Method not allowed.']);
}

$data = json_decode(file_get_contents('php://input'), true);

if (!is_array($data) || !is_valid_lead($data)) {
    json_response(422, [
        'ok' => false,
        'message' => 'Please provide valid name, company, phone, email, and message details.',
    ]);
}

$lead = [
    'created_at' => gmdate('c'),
    'name' => trim((string) $data['name']),
    'company' => trim((string) ($data['company'] ?? $data['customerType'] ?? $data['type'] ?? $data['name'])),
    'customer_type' => trim((string) ($data['customerType'] ?? $data['type'] ?? '')),
    'product' => trim((string) ($data['product'] ?? $data['productName'] ?? '')),
    'quantity' => trim((string) ($data['quantity'] ?? '')),
    'country_code' => trim((string) ($data['countryCode'] ?? '')),
    'phone' => trim((string) ($data['phone'] ?? $data['phoneNumber'] ?? '')),
    'email' => strtolower(trim((string) $data['email'])),
    'service' => trim((string) ($data['service'] ?? $data['typeOfService'] ?? '')),
    'message' => trim((string) ($data['message'] ?? '')),
    'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
];

send_to_zoho($lead);

json_response(200, ['ok' => true, 'message' => 'Lead submitted successfully.']);
