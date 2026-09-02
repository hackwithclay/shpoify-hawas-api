<?php
/**
 * Zoho CRM lead endpoint for the World of Hawas Shopify store.
 *
 * The Shopify storefront cannot call Zoho directly: doing so would require the
 * client secret and refresh token to be present in a public web page, which
 * would hand any visitor full read/write access to the CRM. This endpoint keeps
 * the credentials server-side and is the only thing the storefront talks to.
 *
 * Drop this on the same host as the ASD landing page, which already holds these
 * credentials, e.g. https://worldofhawas.com/api/shopify-lead.php
 *
 * It reuses the landing page's existing config array unchanged. If config/zoho.php
 * is not alongside this file, set ZOHO_CONFIG_PATH.
 */

declare(strict_types=1);

// ---------------------------------------------------------------- config

$configPath = getenv('ZOHO_CONFIG_PATH') ?: __DIR__ . '/config/zoho.php';
if (!is_readable($configPath)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Zoho config not found']);
    exit;
}
$config = require $configPath;

/**
 * Only these origins may post here. A wildcard would let any site on the
 * internet submit leads into the CRM.
 */
$allowedOrigins = [
    'https://gsbjq7-bc.myshopify.com',
    'https://worldofhawas.com',
    'https://www.worldofhawas.com',
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function fail(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    fail(405, 'Method not allowed');
}
if (empty($config['enabled'])) {
    fail(503, 'Zoho integration disabled');
}
if ($origin !== '' && !in_array($origin, $allowedOrigins, true)) {
    fail(403, 'Origin not allowed');
}

// ----------------------------------------------------------------- input

$raw = file_get_contents('php://input') ?: '';
$input = json_decode($raw, true);
if (!is_array($input)) {
    $input = $_POST;                       // tolerate a normal form post
}

function field(array $src, string $key): string
{
    $value = $src[$key] ?? '';
    return is_scalar($value) ? trim((string) $value) : '';
}

$name    = field($input, 'name');
$email   = field($input, 'email');
$phone   = field($input, 'phone');
$company = field($input, 'company');
$service = field($input, 'service');
$message = field($input, 'message');
$product = field($input, 'product');
$quantity = field($input, 'quantity');
$audience = field($input, 'audience');

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail(422, 'A valid email address is required');
}

// Zoho requires Last_Name. Split a single name field on the last space.
$name = $name !== '' ? $name : 'Website enquiry';
$parts = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
$lastName = count($parts) > 1 ? array_pop($parts) : $name;
$firstName = count($parts) > 1 ? implode(' ', $parts) : '';

// Anything without a dedicated Zoho field is folded into the description,
// so nothing the customer typed is silently dropped.
$notes = [];
if ($message !== '')  { $notes[] = $message; }
if ($product !== '')  { $notes[] = 'Product: ' . $product; }
if ($quantity !== '') { $notes[] = 'Quantity: ' . $quantity; }
if ($audience !== '') { $notes[] = 'Enquirer type: ' . $audience; }

$map = $config['field_map'];
$record = [
    $map['last_name']   => $lastName,
    $map['email']       => $email,
    $map['lead_source'] => $config['lead_source'],
];
if ($firstName !== '') { $record[$map['first_name']] = $firstName; }
if ($phone !== '')     { $record[$map['phone']]      = $phone; }
if ($company !== '')   { $record[$map['company']]    = $company; }
if ($message !== '' || $notes) { $record[$map['message']] = implode("\n", $notes); }

// Type_of_Service is a picklist; sending anything off-list makes Zoho reject
// the whole record, so unrecognised values are dropped into the description.
$allowedServices = [
    'PR/Media', 'Influencers/Creators', 'PreOrder', 'Fragrance Retailers',
    'Distributors & Importers', 'E-Commerce Retailers', 'Hotel Chains',
];
if ($service !== '') {
    if (in_array($service, $allowedServices, true)) {
        $record[$map['service']] = $service;
    } else {
        $record[$map['message']] = trim(($record[$map['message']] ?? '') . "\nType of service: " . $service);
    }
}

// User_ID is the dedupe key; the config sources it from the email.
$record[$map['user_id']] = ($config['user_id_source'] ?? 'email') === 'email'
    ? strtolower($email)
    : strtolower($email);

// ----------------------------------------------------------------- token

/**
 * Access tokens last an hour. Cache one so a burst of enquiries does not
 * hammer the token endpoint and trip Zoho's refresh limits.
 */
function accessToken(array $config): string
{
    $cacheFile = sys_get_temp_dir() . '/zoho_access_token_' . md5($config['client_id']) . '.json';

    if (is_readable($cacheFile)) {
        $cached = json_decode((string) file_get_contents($cacheFile), true);
        if (is_array($cached) && ($cached['expires_at'] ?? 0) > time() + 60) {
            return (string) $cached['access_token'];
        }
    }

    $url = $config['accounts_domain'] . '/oauth/v2/token?' . http_build_query([
        'refresh_token' => $config['refresh_token'],
        'client_id'     => $config['client_id'],
        'client_secret' => $config['client_secret'],
        'grant_type'    => 'refresh_token',
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode((string) $body, true);
    if ($status !== 200 || empty($data['access_token'])) {
        throw new RuntimeException('Could not obtain a Zoho access token');
    }

    @file_put_contents($cacheFile, json_encode([
        'access_token' => $data['access_token'],
        'expires_at'   => time() + (int) ($data['expires_in'] ?? 3600),
    ]), LOCK_EX);
    @chmod($cacheFile, 0600);

    return (string) $data['access_token'];
}

// ---------------------------------------------------------------- upsert

try {
    $token = accessToken($config);
} catch (Throwable $e) {
    error_log('[zoho-lead] ' . $e->getMessage());
    fail(502, 'Upstream authentication failed');
}

$payload = json_encode([
    'data' => [$record],
    'duplicate_check_fields' => $config['duplicate_check_fields'],
], JSON_UNESCAPED_UNICODE);

$ch = curl_init($config['api_domain'] . '/crm/v8/' . $config['module'] . '/upsert');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Zoho-oauthtoken ' . $token,
        'Content-Type: application/json',
    ],
]);
$body = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$result = json_decode((string) $body, true);
$entry = $result['data'][0] ?? [];

if ($status >= 200 && $status < 300 && ($entry['code'] ?? '') === 'SUCCESS') {
    echo json_encode([
        'ok' => true,
        'action' => $entry['action'] ?? null,
        'id' => $entry['details']['id'] ?? null,
    ]);
    exit;
}

// Log the detail, return something generic - Zoho's errors can echo field data.
error_log('[zoho-lead] upsert failed: HTTP ' . $status . ' ' . substr((string) $body, 0, 500));
fail(502, 'Lead could not be created');
