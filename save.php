<?php
/**
 * Cash N Carry – Check-in PDF Receiver
 * Forwards base64 PDF to Google Apps Script Web App, which saves it to Drive
 * under the deploying user's Google account (no service account quota needed).
 */

// ── Apps Script Web App URL ────────────────────────────────────────────────
define('APPS_SCRIPT_URL', 'https://script.google.com/macros/s/AKfycbw0ln62ghdq4qCRmy18DExhL5ydIhdAArwsCVUnUdEVLO9XyuAxNy2faQHskfB8Jn_4/exec');

// ── CORS ───────────────────────────────────────────────────────────────────
$allowed_origin = 'https://checkin.cashncarryparts.com';
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin === $allowed_origin) {
    header('Access-Control-Allow-Origin: ' . $allowed_origin);
}
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(204);
    exit;
}

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
if (str_contains($base64, ',')) {
    $base64 = explode(',', $base64, 2)[1];
}

$pdfBytes = base64_decode($base64, strict: true);
if ($pdfBytes === false || !str_starts_with($pdfBytes, '%PDF-')) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid PDF data']);
    exit;
}

// ── Sanitise filename ──────────────────────────────────────────────────────
$rawName  = basename($data['filename']);
$safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($rawName, PATHINFO_FILENAME));
$safeName = substr($safeName, 0, 120);
$filename = $safeName . '.pdf';

// ── Forward to Apps Script via cURL (handles Google's redirect chain) ───────
$phone = isset($data['phone']) ? preg_replace('/[^0-9A-Za-z]/', '', substr($data['phone'], 0, 20)) : 'Unknown';
$payload = json_encode(['pdf' => $base64, 'filename' => $filename, 'phone' => $phone]);

$ch = curl_init(APPS_SCRIPT_URL);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => true,
]);

$response = curl_exec($ch);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($response === false) {
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'cURL error: ' . $curlErr]);
    exit;
}

$result = json_decode($response, true);

if (isset($result['success']) && $result['success']) {
    http_response_code(200);
    echo json_encode($result);
} else {
    http_response_code(500);
    echo json_encode($result ?? ['success' => false, 'error' => 'Apps Script raw response: ' . substr($response, 0, 300)]);
}
