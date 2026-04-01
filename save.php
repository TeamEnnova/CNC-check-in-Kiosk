<?php
/**
 * Cash N Carry – Check-in PDF Receiver
 * Uploads PDF directly to Google Drive via Service Account (no library needed)
 *
 * Requires: service-account.json  one level above public_html
 *   /home/appodinc/domains/checkin.cashncarryparts.com/service-account.json
 */

// ── CORS (allow the same domain only) ──────────────────────────────────────
$allowed_origin = 'https://checkin.cashncarryparts.com';
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin === $allowed_origin) {
    header('Access-Control-Allow-Origin: ' . $allowed_origin);
}
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(204);
    exit;
}

// ── Only allow POST ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// ── Parse JSON body ────────────────────────────────────────────────────────
$body = file_get_contents('php://input');
$data = json_decode($body, true);

if (!$data || !isset($data['pdf'], $data['filename'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

// ── Validate base64 PDF ────────────────────────────────────────────────────
$base64 = $data['pdf'];

// Strip data-URI prefix if present
if (str_contains($base64, ',')) {
    $base64 = explode(',', $base64, 2)[1];
}

$pdfBytes = base64_decode($base64, strict: true);
if ($pdfBytes === false) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid base64 data']);
    exit;
}

// Confirm it's a PDF by checking magic bytes (%PDF-)
if (!str_starts_with($pdfBytes, '%PDF-')) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'File is not a valid PDF']);
    exit;
}

// ── Sanitise filename ──────────────────────────────────────────────────────
$rawName  = basename($data['filename']);
$safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($rawName, PATHINFO_FILENAME));
$safeName = substr($safeName, 0, 120);
$filename = $safeName . '.pdf';

// ── Load service account key (outside public_html) ────────────────────────
$keyPath = dirname(__DIR__) . '/service-account.json';
if (!file_exists($keyPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'service-account.json not found on server']);
    exit;
}

$serviceAccount = json_decode(file_get_contents($keyPath), true);
if (!$serviceAccount || !isset($serviceAccount['client_email'], $serviceAccount['private_key'])) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Invalid service account key file']);
    exit;
}

// ── Google Drive folder ID ─────────────────────────────────────────────────
define('DRIVE_FOLDER_ID', '1jQXO9JnPC9lBk0-ukRAghcGKTyr2gk96');

// ── JWT helper ────────────────────────────────────────────────────────────
function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

// ── Get OAuth2 access token via Service Account JWT ───────────────────────
function get_drive_access_token(array $sa): string|false {
    $now    = time();
    $header  = base64url_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $payload = base64url_encode(json_encode([
        'iss'   => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/drive.file',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ]));

    $signingInput = $header . '.' . $payload;
    $privateKey   = openssl_pkey_get_private($sa['private_key']);
    if (!$privateKey) return false;

    openssl_sign($signingInput, $signature, $privateKey, 'SHA256');
    $jwt = $signingInput . '.' . base64url_encode($signature);

    $response = file_get_contents('https://oauth2.googleapis.com/token', false, stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]),
            'timeout'       => 10,
            'ignore_errors' => true,
        ],
    ]));

    if ($response === false) return false;
    $token = json_decode($response, true);
    return $token['access_token'] ?? false;
}

// ── Upload PDF to Google Drive (multipart) ────────────────────────────────
function upload_to_drive(string $pdfBytes, string $filename, string $folderId, string $accessToken): array {
    $metadata = json_encode(['name' => $filename, 'parents' => [$folderId]]);
    $boundary = '----DriveUpload' . bin2hex(random_bytes(8));

    $body  = "--{$boundary}\r\n";
    $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
    $body .= $metadata . "\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: application/pdf\r\n\r\n";
    $body .= $pdfBytes . "\r\n";
    $body .= "--{$boundary}--";

    $response = file_get_contents(
        'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart',
        false,
        stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => implode("\r\n", [
                    'Authorization: Bearer ' . $accessToken,
                    'Content-Type: multipart/related; boundary=' . $boundary,
                    'Content-Length: ' . strlen($body),
                ]),
                'content'       => $body,
                'timeout'       => 30,
                'ignore_errors' => true,
            ],
        ])
    );

    if ($response === false) {
        return ['success' => false, 'error' => 'Drive upload network request failed'];
    }

    $result = json_decode($response, true);
    if (isset($result['id'])) {
        return ['success' => true, 'fileId' => $result['id'], 'filename' => $filename];
    }

    return ['success' => false, 'error' => $result['error']['message'] ?? 'Unknown Drive API error'];
}

// ── Execute ────────────────────────────────────────────────────────────────
$accessToken = get_drive_access_token($serviceAccount);
if (!$accessToken) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not authenticate with Google Drive']);
    exit;
}

$result = upload_to_drive($pdfBytes, $filename, DRIVE_FOLDER_ID, $accessToken);
http_response_code($result['success'] ? 200 : 500);
echo json_encode($result);
