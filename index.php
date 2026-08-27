<?php

declare(strict_types=1);

function env_value(string $key, string $default = ''): string
{
    return getenv($key) !== false ? (string) getenv($key) : $default;
}

function zoho_config(): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $config = [];
    $paths = array_filter([
        env_value('ZOHO_CONFIG_PATH'),
        __DIR__ . DIRECTORY_SEPARATOR . 'zoho-config.php',
        'C:\\Users\\Nikhil\\OneDrive\\Documents\\zoho-config.php',
    ]);

    foreach ($paths as $path) {
        if (!is_readable($path)) {
            continue;
        }

        $loaded = require $path;
        if (is_array($loaded)) {
            $config = $loaded;
            break;
        }
    }

    return $config;
}

function config_value(string $envKey, string $configKey, string $default = ''): string
{
    $env = env_value($envKey);
    if ($env !== '') {
        return $env;
    }

    $value = zoho_config()[$configKey] ?? $default;
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }

    return is_scalar($value) ? (string) $value : $default;
}

function zoho_field(string $leadKey, string $envKey, string $default): string
{
    $env = env_value($envKey);
    if ($env !== '') {
        return $env;
    }

    $fieldMap = zoho_config()['field_map'] ?? [];
    $value = is_array($fieldMap) ? ($fieldMap[$leadKey] ?? $default) : $default;

    return is_string($value) && $value !== '' ? $value : $default;
}

function zoho_duplicate_check_fields(string $defaultField): array
{
    $env = env_value('ZOHO_DUPLICATE_CHECK_FIELDS');
    if ($env !== '') {
        return array_values(array_filter(array_map('trim', explode(',', $env))));
    }

    $fields = zoho_config()['duplicate_check_fields'] ?? [$defaultField];
    if (!is_array($fields)) {
        return [$defaultField];
    }

    $fields = array_values(array_filter(array_map(
        static fn ($field): string => trim((string) $field),
        $fields
    )));

    return $fields !== [] ? $fields : [$defaultField];
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
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';

    header("Access-Control-Allow-Origin: " . ($origin !== '' ? $origin : '*'));
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Origin, Accept");
    header("Access-Control-Max-Age: 86400");
    header("Vary: Origin");

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

function is_valid_lead(array $data): bool
{
    $name = trim((string) ($data['name'] ?? ''));
    $rawPhone = trim((string) ($data['phone'] ?? $data['phoneNumber'] ?? ''));
    $phoneDigits = preg_replace('/\D+/', '', $rawPhone);
    $email = trim((string) ($data['email'] ?? ''));
    $message = trim((string) ($data['message'] ?? ''));
    $quantity = trim((string) ($data['quantity'] ?? ''));
    $validQuantity = $quantity === '' || preg_match('/^\d+$/', $quantity);

    // If email is provided, validate format; if empty, allow it
    $validEmail = ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) !== false);

    return strlen($name) >= 3
        && strlen($phoneDigits) >= 7 && strlen($phoneDigits) <= 15
        && $validEmail
        && strlen($message) <= 2000
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
    $url = rtrim(config_value('ZOHO_ACCOUNTS_DOMAIN', 'accounts_domain', 'https://accounts.zoho.com'), '/') . '/oauth/v2/token';
    $postFields = http_build_query([
        'refresh_token' => config_value('ZOHO_REFRESH_TOKEN', 'refresh_token'),
        'client_id' => config_value('ZOHO_CLIENT_ID', 'client_id'),
        'client_secret' => config_value('ZOHO_CLIENT_SECRET', 'client_secret'),
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
    if (config_value('ZOHO_ENABLED', 'enabled', 'true') !== 'true') {
        return;
    }

    $requiredConfig = [
        ['ZOHO_CLIENT_ID', 'client_id'],
        ['ZOHO_CLIENT_SECRET', 'client_secret'],
        ['ZOHO_REFRESH_TOKEN', 'refresh_token'],
    ];

    foreach ($requiredConfig as [$envKey, $configKey]) {
        if (config_value($envKey, $configKey) === '') {
            json_response(500, ['ok' => false, 'message' => 'Zoho CRM is not configured correctly.']);
        }
    }

    [$firstName, $lastName] = split_name($lead['name']);

    $userId = strtolower($lead['email']);
    if (empty($userId) || config_value('ZOHO_USER_ID_SOURCE', 'user_id_source', 'email') === 'phone') {
        $userId = preg_replace('/\D+/', '', $lead['country_code'] . $lead['phone']);
    }

    $userIdField = zoho_field('user_id', 'ZOHO_FIELD_USER_ID', 'User_ID');

    $record = [
        $userIdField => $userId,
        zoho_field('first_name', 'ZOHO_FIELD_FIRST_NAME', 'First_Name') => $firstName,
        zoho_field('last_name', 'ZOHO_FIELD_LAST_NAME', 'Last_Name') => $lastName,
        zoho_field('company', 'ZOHO_FIELD_COMPANY', 'Company') => $lead['company'],
        zoho_field('phone', 'ZOHO_FIELD_PHONE', 'Phone') => $lead['country_code'] . $lead['phone'],
        zoho_field('email', 'ZOHO_FIELD_EMAIL', 'Email') => $lead['email'],
        zoho_field('lead_source', 'ZOHO_FIELD_LEAD_SOURCE', 'Lead_Source') => config_value('ZOHO_LEAD_SOURCE', 'lead_source', 'Shopify'),
        zoho_field('service', 'ZOHO_FIELD_SERVICE', 'Service') => $lead['service'],
        zoho_field('message', 'ZOHO_FIELD_MESSAGE', 'Description') => $lead['message'],
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
        'duplicate_check_fields' => zoho_duplicate_check_fields($userIdField),
    ];

    $url = rtrim(config_value('ZOHO_API_DOMAIN', 'api_domain', 'https://www.zohoapis.com'), '/')
        . '/crm/v8/'
        . rawurlencode(config_value('ZOHO_MODULE', 'module', 'Leads'))
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

// 1. Run CORS headers immediately
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

$data = json_decode((string) file_get_contents('php://input'), true);

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
    'email' => strtolower(trim((string) ($data['email'] ?? ''))),
    'service' => trim((string) ($data['service'] ?? $data['typeOfService'] ?? '')),
    'message' => trim((string) ($data['message'] ?? '')),
    'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
];

send_to_zoho($lead);

json_response(200, ['ok' => true, 'message' => 'Lead submitted successfully.']);
