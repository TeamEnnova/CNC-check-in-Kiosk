<?php
/**
 * Cash N Carry – Check-in PDF Receiver
 * Accepts a base64-encoded PDF via POST and saves it to /checkins/
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
$rawName  = basename($data['filename']);                     // strip any path
$safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($rawName, PATHINFO_FILENAME));
$safeName = substr($safeName, 0, 120);                      // hard length cap
$filename = $safeName . '.pdf';

// ── Save directory ─────────────────────────────────────────────────────────
// __DIR__ = …/public_html   →   dirname(__DIR__) = …/checkin.cashncarryparts.com
// Storing ONE level above public_html means no URL can ever reach these files.
$saveDir = dirname(__DIR__) . '/checkins/';

if (!is_dir($saveDir)) {
    if (!mkdir($saveDir, 0750, true)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Could not create storage directory']);
        exit;
    }
}

// ── Write file ─────────────────────────────────────────────────────────────
$fullPath = $saveDir . $filename;

// If a file with the same name exists, append a counter
$counter = 1;
while (file_exists($fullPath)) {
    $fullPath = $saveDir . $safeName . '_' . $counter . '.pdf';
    $counter++;
}

$written = file_put_contents($fullPath, $pdfBytes);
if ($written === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to write file']);
    exit;
}

// ── Success ────────────────────────────────────────────────────────────────
http_response_code(200);
echo json_encode([
    'success'  => true,
    'filename' => basename($fullPath),
    'size'     => $written,
]);
