<?php
/**
 * Gemini Vision – marine survey image description proxy.
 * API key stays on the server; never exposed to the browser.
 *
 * POST JSON:
 *   { "image": "<base64>", "mime": "image/jpeg" }
 *   optional: "mime_type" (alias of mime)
 *
 * Response JSON:
 *   { "success": true, "description": "..." }
 *   { "success": false, "message": "..." }
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// CORS not required when same-origin; keep strict.
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST only.']);
    exit;
}

// Load config if available (for GEMINI_API_KEY via env or define).
$configPath = dirname(__DIR__) . '/config/config.php';
if (is_file($configPath)) {
    // Avoid session/auth side-effects if config starts a session — still OK.
    require_once $configPath;
}

// Prefer env / define; never hard-code a real key in the repo.
$apiKey = '';
if (defined('GEMINI_API_KEY') && GEMINI_API_KEY !== '') {
    $apiKey = (string) GEMINI_API_KEY;
} elseif (getenv('YSMS_GEMINI_API_KEY')) {
    $apiKey = (string) getenv('YSMS_GEMINI_API_KEY');
} elseif (getenv('GEMINI_API_KEY')) {
    $apiKey = (string) getenv('GEMINI_API_KEY');
}

if ($apiKey === '') {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'message' => 'Gemini API key is not configured on the server. Set YSMS_GEMINI_API_KEY or GEMINI_API_KEY.',
    ]);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    // Fallback: multipart form (field "image" file)
    if (!empty($_FILES['image']['tmp_name']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
        $bin = file_get_contents($_FILES['image']['tmp_name']);
        $mime = $_FILES['image']['type'] ?: 'image/jpeg';
        $b64 = base64_encode($bin);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON body.']);
        exit;
    }
} else {
    $b64 = isset($data['image']) ? (string) $data['image'] : '';
    // Strip data-URL prefix if the client sent one
    if (preg_match('#^data:([^;]+);base64,#i', $b64, $m)) {
        $mime = $m[1];
        $b64 = substr($b64, strpos($b64, ',') + 1);
    } else {
        $mime = (string) ($data['mime'] ?? $data['mime_type'] ?? 'image/jpeg');
    }
}

$b64 = preg_replace('/\s+/', '', $b64);
if ($b64 === '' || strlen($b64) < 100) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Image data is missing or too small.']);
    exit;
}

// Cap ~4 MB base64 (~3 MB binary) to protect server memory
if (strlen($b64) > 4 * 1024 * 1024) {
    http_response_code(413);
    echo json_encode(['success' => false, 'message' => 'Image too large. Please use a smaller image.']);
    exit;
}

$allowedMimes = [
    'image/jpeg' => true,
    'image/jpg'  => true,
    'image/png'  => true,
    'image/webp' => true,
    'image/gif'  => true,
    'image/heic' => true,
    'image/heif' => true,
];
$mime = strtolower(trim($mime));
if ($mime === 'image/jpg') {
    $mime = 'image/jpeg';
}
if (!isset($allowedMimes[$mime])) {
    $mime = 'image/jpeg';
}

$prompt = 'You are a professional Marine Cargo, Hull, and Bunker Surveyor with over 20 years of experience.
Analyze ONLY what is clearly visible in the uploaded image.
Your task is to identify the MAIN visible object or structure and generate a professional marine survey photo description.

Rules:
1. Identify the primary object only (Bulkhead, Bulk Frame, Hopper, Tank Top, Coaming, Hatch Cover, Side Shell, Cargo Hold, Pipe, Ladder, Manhole, Bilge Well, etc.).
2. Identify its visible position whenever possible (Port Side, Starboard Side, Forward, Aft, Upper, Lower, Center, Fore End, Aft End, etc.).
3. Mention only visible conditions.
4. If damage is visible, mention the damage type such as: Corrosion, Surface Rust, Heavy Rust Scale, Coating Breakdown, Coating Peeling, Crack, Dent, Deformation, Pitting, Indentation, Buckling, Cargo Residue, Water Stain, Temporary Repair.
5. Never guess information that is not clearly visible.
6. If no damage is visible, write \"No visible damage observed.\"
7. Keep the description professional and concise.
8. Return ONLY one complete sentence.
9. Do not include introductions, explanations, reasoning steps, confidence scores, markdown, bullets, asterisks, or quotation marks.
10. Never start with phrases like \"Let\'s\", \"I can see\", \"Looking at\", \"The image shows\", or any thinking text.

Output Format (strict):
View of [Object Name] at [Position], showing [Visible Condition].

Examples:
View of Port Side Bulkhead, showing moderate surface corrosion.
View of Starboard Lower Hopper, showing coating breakdown and localized rust.
View of Tank Top at Centre, showing clean surface with no visible damage observed.
View of Forward Hatch Coaming, showing minor paint peeling.
View of Bulk Frame, showing heavy rust scale and coating deterioration.
View of Bilge Well, showing clean condition with no visible damage observed.

If the object or position cannot be confidently determined, use:
View of marine structure, showing [Visible Condition].

Return only that one sentence and nothing else.';

// Latest stable multimodal Flash model (vision). Change via env if needed.
$model = getenv('YSMS_GEMINI_MODEL') ?: (defined('GEMINI_MODEL') ? GEMINI_MODEL : 'gemini-3.6-flash');
$url = 'https://generativelanguage.googleapis.com/v1beta/models/'
    . rawurlencode($model)
    . ':generateContent?key=' . rawurlencode($apiKey);

$payload = [
    'contents' => [
        [
            'parts' => [
                ['text' => $prompt],
                [
                    'inline_data' => [
                        'mime_type' => $mime,
                        'data'      => $b64,
                    ],
                ],
            ],
        ],
    ],
    'generationConfig' => [
        'temperature'     => 0.2,
        'maxOutputTokens' => 180,
    ],
];

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_CONNECTTIMEOUT => 15,
]);
$response = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($response === false || $response === '') {
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'message' => 'Could not reach Gemini API' . ($curlErr ? (': ' . $curlErr) : '.'),
    ]);
    exit;
}

$json = json_decode($response, true);
if ($httpCode >= 400 || !is_array($json)) {
    $msg = 'Gemini API error (HTTP ' . $httpCode . ').';
    if (is_array($json) && isset($json['error']['message'])) {
        $msg = $json['error']['message'];
    }
    $retryAfter = null;
    if (preg_match('/retry in\s*([0-9]+(?:\.[0-9]+)?)\s*s/i', $msg, $rm)) {
        $retryAfter = (int) ceil((float) $rm[1]) + 1;
    }
    http_response_code($httpCode >= 400 ? $httpCode : 502);
    $out = ['success' => false, 'message' => $msg];
    if ($retryAfter !== null) {
        $out['retry_after'] = $retryAfter;
    }
    echo json_encode($out);
    exit;
}

$description = '';
if (!empty($json['candidates'][0]['content']['parts'])) {
    foreach ($json['candidates'][0]['content']['parts'] as $part) {
        if (isset($part['text'])) {
            $description .= $part['text'];
        }
    }
}
$description = trim(preg_replace('/\s+/u', ' ', $description));

// Strip accidental quotes / markdown wrappers and model reasoning leakage
$description = trim($description, " \t\n\r\0\x0B\"'`");
// Drop leading junk like ". * Let's identify..." or markdown bullets
$description = preg_replace('/^[\\.\\*\\-\\s]+/', '', $description);
$description = preg_replace('/^(?:Let\'s|I can see|Looking at|The image shows|Here is|Based on)[^.]*?[.!?]?\s*/i', '', $description);
$description = trim($description);
// Keep only the first sentence that starts with "View of" if mixed garbage present
if (preg_match('/View of\s+.+?(?:\.|$)/i', $description, $vm)) {
    $description = trim($vm[0]);
}
// Ensure it ends with a period
if ($description !== '' && !preg_match('/[.!?]$/', $description)) {
    $description .= '.';
}

if ($description === '') {
    $blockReason = $json['candidates'][0]['finishReason'] ?? ($json['promptFeedback']['blockReason'] ?? '');
    echo json_encode([
        'success' => false,
        'message' => 'No description returned' . ($blockReason ? (' (' . $blockReason . ')') : '.') ,
    ]);
    exit;
}

echo json_encode([
    'success'     => true,
    'description' => $description,
]);
