<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function respond(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function extractDriveId(string $url): ?string {
    $parts = parse_url($url);
    if (!$parts || !isset($parts['host']) || !preg_match('/(^|\.)drive\.google\.com$/i', $parts['host'])) {
        return null;
    }

    $path = $parts['path'] ?? '';
    if (preg_match('#/(?:file/d|drive/folders)/([a-zA-Z0-9_-]+)#', $path, $match)) {
        return $match[1];
    }

    parse_str($parts['query'] ?? '', $query);
    return isset($query['id']) && preg_match('/^[a-zA-Z0-9_-]+$/', $query['id']) ? $query['id'] : null;
}

function formatBytes(?int $bytes): string {
    if ($bytes === null || $bytes < 0) return 'Archivo de Drive';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $index = 0;
    $value = (float) $bytes;
    while ($value >= 1024 && $index < count($units) - 1) {
        $value /= 1024;
        $index++;
    }
    return ($index === 0 ? (string) (int) $value : number_format($value, $value < 10 ? 1 : 0, '.', '')) . ' ' . $units[$index];
}

function base64UrlEncode(string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function getServiceAccessToken(): string {
    $credentialsPath = getenv('GOOGLE_DRIVE_SERVICE_ACCOUNT_FILE');
    if (!$credentialsPath || !is_readable($credentialsPath)) {
        respond(500, ['error' => 'Falta configurar la cuenta de servicio de Google Drive en el servidor.']);
    }

    $credentials = json_decode((string) file_get_contents($credentialsPath), true);
    if (!is_array($credentials) || empty($credentials['client_email']) || empty($credentials['private_key'])) {
        respond(500, ['error' => 'La configuración de la cuenta de servicio de Google Drive no es válida.']);
    }

    $now = time();
    $header = base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claims = base64UrlEncode(json_encode([
        'iss' => $credentials['client_email'],
        'scope' => 'https://www.googleapis.com/auth/drive.metadata.readonly',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3600,
    ]));
    $unsignedToken = $header . '.' . $claims;
    if (!openssl_sign($unsignedToken, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
        respond(500, ['error' => 'No fue posible firmar la solicitud para Google Drive.']);
    }

    $tokenRequest = http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $unsignedToken . '.' . base64UrlEncode($signature),
    ]);
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($tokenRequest),
        'content' => $tokenRequest,
        'timeout' => 8,
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents('https://oauth2.googleapis.com/token', false, $context);
    $response = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($response) || empty($response['access_token'])) {
        respond(502, ['error' => 'No fue posible autenticar el servicio con Google Drive.']);
    }

    return $response['access_token'];
}

$url = trim((string) ($_GET['url'] ?? ''));
$id = extractDriveId($url);
if (!$id) respond(400, ['error' => 'Pega un enlace válido de un archivo o carpeta de Google Drive.']);

$fields = 'id,name,mimeType,size,webViewLink';
$endpoint = 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($id) . '?' . http_build_query([
    'fields' => $fields,
    'supportsAllDrives' => 'true',
]);
$accessToken = getServiceAccessToken();
$context = stream_context_create(['http' => [
    'method' => 'GET',
    'header' => "Authorization: Bearer {$accessToken}\r\n",
    'timeout' => 8,
    'ignore_errors' => true,
]]);
$raw = @file_get_contents($endpoint, false, $context);
$statusLine = $http_response_header[0] ?? '';
$status = preg_match('#\s(\d{3})\s#', $statusLine, $match) ? (int) $match[1] : 502;
$data = is_string($raw) ? json_decode($raw, true) : null;

if ($status !== 200 || !is_array($data)) {
    $message = $status === 404 || $status === 403
        ? 'Drive no pudo acceder al elemento. Revisa que esté compartido como “Cualquier persona con el enlace puede ver”.'
        : 'No fue posible consultar Drive. Intenta de nuevo en un momento.';
    respond($status === 403 || $status === 404 ? 422 : 502, ['error' => $message]);
}

$isFolder = ($data['mimeType'] ?? '') === 'application/vnd.google-apps.folder';
respond(200, [
    'name' => $data['name'] ?? 'Archivo de Google Drive',
    'kind' => $isFolder ? 'folder' : 'file',
    'size' => $isFolder ? 'Carpeta' : formatBytes(isset($data['size']) ? (int) $data['size'] : null),
    'url' => $data['webViewLink'] ?? $url,
]);
