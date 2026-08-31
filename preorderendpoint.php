<?php
/**
 * World of Hawas - pre-order endpoint.
 *
 * The storefront form (snippets/preorder-modal.liquid) posts here. This file
 * creates a DRAFT ORDER in Shopify, so the pre-order appears in the admin under
 * Orders > Drafts with the customer's details and the product already attached.
 * The customer never goes through checkout.
 *
 * Why a server: creating a draft order needs a Shopify Admin API token. A theme
 * is public, so the token can only live here.
 *
 * DEPLOY
 *   1. Put this file somewhere it can be reached over HTTPS.
 *   2. Create shopify-preorder.config.php beside it (see CONFIG_FILE below).
 *   3. Paste this file's URL into
 *      Theme settings > Pre-orders > Pre-order endpoint URL,
 *      and switch on "Show the pre-order button on product pages".
 *
 * THE TOKEN
 *   Shopify admin > Settings > Apps and sales channels > Develop apps >
 *   Create an app > Configure Admin API scopes > tick write_draft_orders
 *   (read_draft_orders is useful too) > Install > reveal the Admin API access
 *   token. It starts with shpat_ and is shown once.
 */

declare(strict_types=1);

const SHOP_DOMAIN   = 'gsbjq7-bc.myshopify.com';
const API_VERSION   = '2025-07';
const CONFIG_FILE   = __DIR__ . '/shopify-preorder.config.php';
const DRAFT_TAG     = 'preorder';
const MAX_QUANTITY  = 99;

/** Origins allowed to call this endpoint. */
const ALLOWED_ORIGINS = [
    'https://gsbjq7-bc.myshopify.com',
    'https://worldofhawas.com',
    'https://www.worldofhawas.com',
];

/* ------------------------------------------------------------------ CORS --- */

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, ALLOWED_ORIGINS, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    fail(405, 'Method not allowed.');
}
if ($origin !== '' && !in_array($origin, ALLOWED_ORIGINS, true)) {
    fail(403, 'Origin not allowed.');
}

/* ---------------------------------------------------------------- helpers --- */

function fail(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

function clean($value, int $limit = 255): string
{
    if (!is_scalar($value)) {
        return '';
    }
    $value = trim((string) $value);
    $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
    return mb_substr($value, 0, $limit);
}

/* ------------------------------------------------------------------ input --- */

$raw = file_get_contents('php://input') ?: '';
if (strlen($raw) > 20000) {
    fail(413, 'Payload too large.');
}
$in = json_decode($raw, true);
if (!is_array($in)) {
    fail(400, 'Expected a JSON body.');
}

$variantId = preg_replace('/\D+/', '', (string) ($in['variantId'] ?? ''));
$email     = clean($in['email'] ?? '');
$firstName = clean($in['firstName'] ?? '', 60);
$lastName  = clean($in['lastName'] ?? '', 60);
$phone     = clean($in['phone'] ?? '', 40);
$address   = clean($in['address'] ?? '');
$city      = clean($in['city'] ?? '', 80);
$country   = clean($in['country'] ?? '', 80);
$product   = clean($in['product'] ?? '');
$quantity  = (int) ($in['quantity'] ?? 1);
$quantity  = max(1, min(MAX_QUANTITY, $quantity));

if ($variantId === '') {
    fail(422, 'Missing product.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail(422, 'A valid email address is required.');
}
if ($firstName === '' && $lastName === '') {
    fail(422, 'A name is required.');
}
if ($phone === '') {
    fail(422, 'A phone number is required.');
}
if ($address === '') {
    fail(422, 'An address is required.');
}

/* ------------------------------------------------------------------ token --- */

if (!is_readable(CONFIG_FILE)) {
    error_log('preorder: missing ' . CONFIG_FILE);
    fail(500, 'Pre-orders are not configured.');
}
/** @var array{admin_token?: string} $config */
$config = require CONFIG_FILE;
$token = (string) ($config['admin_token'] ?? '');
if ($token === '') {
    error_log('preorder: admin_token is empty');
    fail(500, 'Pre-orders are not configured.');
}

/* ------------------------------------------------------------ draft order --- */

$shipping = [
    'firstName' => $firstName !== '' ? $firstName : $lastName,
    'lastName'  => $lastName,
    'address1'  => $address,
    'phone'     => $phone,
];
if ($city !== '') {
    $shipping['city'] = $city;
}
if ($country !== '') {
    $shipping['country'] = $country;
}

$note = "Pre-order placed from the product page.\n"
      . 'Product: ' . ($product !== '' ? $product : 'variant ' . $variantId) . "\n"
      . 'Phone: ' . $phone;

$mutation = <<<'GRAPHQL'
mutation CreatePreorder($input: DraftOrderInput!) {
  draftOrderCreate(input: $input) {
    draftOrder { id name }
    userErrors { field message }
  }
}
GRAPHQL;

$variables = [
    'input' => [
        'email'           => $email,
        'phone'           => $phone,
        'note'            => $note,
        'tags'            => [DRAFT_TAG],
        'shippingAddress' => $shipping,
        'billingAddress'  => $shipping,
        'lineItems'       => [[
            'variantId' => 'gid://shopify/ProductVariant/' . $variantId,
            'quantity'  => $quantity,
        ]],
    ],
];

$ch = curl_init(sprintf('https://%s/admin/api/%s/graphql.json', SHOP_DOMAIN, API_VERSION));
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'X-Shopify-Access-Token: ' . $token,
    ],
    CURLOPT_POSTFIELDS     => json_encode(['query' => $mutation, 'variables' => $variables]),
]);
$response = curl_exec($ch);
$status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);

if ($response === false || $status < 200 || $status >= 300) {
    error_log('preorder: Shopify HTTP ' . $status . ' ' . $curlErr . ' ' . (string) $response);
    fail(502, 'Could not reach Shopify.');
}

$body = json_decode((string) $response, true);
$result = $body['data']['draftOrderCreate'] ?? null;

if (!empty($body['errors'])) {
    error_log('preorder: GraphQL errors ' . json_encode($body['errors']));
    fail(502, 'Shopify rejected the pre-order.');
}
if (!empty($result['userErrors'])) {
    error_log('preorder: userErrors ' . json_encode($result['userErrors']));
    fail(422, $result['userErrors'][0]['message'] ?? 'Shopify rejected the pre-order.');
}

$draft = $result['draftOrder'] ?? [];
echo json_encode([
    'ok'    => true,
    'order' => $draft['name'] ?? null,
]);
