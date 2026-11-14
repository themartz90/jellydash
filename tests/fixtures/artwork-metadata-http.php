<?php

declare(strict_types=1);

[$script, $directory, $mode] = $argv;
$server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
if (!is_resource($server)) {
    file_put_contents($directory . '/stderr', $errorCode . ':' . $errorMessage);
    exit(1);
}

file_put_contents($directory . '/ready', stream_socket_get_name($server, false));
$connection = stream_socket_accept($server, 5);
if (!is_resource($connection)) {
    fclose($server);
    exit(2);
}
stream_set_timeout($connection, 5);

$requestLine = trim((string) fgets($connection));
$headers = [];
while (($line = fgets($connection)) !== false && trim($line) !== '') {
    [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
    $headers[strtolower(trim($name))] = trim($value);
}
$body = '';
$remaining = (int) ($headers['content-length'] ?? 0);
while ($remaining > 0 && ($chunk = fread($connection, $remaining)) !== false && $chunk !== '') {
    $body .= $chunk;
    $remaining -= strlen($chunk);
}
file_put_contents($directory . '/request.json', json_encode([
    'line' => $requestLine,
    'headers' => $headers,
    'body' => $body,
], JSON_THROW_ON_ERROR));

if (in_array($mode, ['chunked-large', '429-large'], true)) {
    $status = $mode === '429-large' ? "429 Too Many Requests\r\nRetry-After: 120" : '200 OK';
    fwrite($connection, 'HTTP/1.1 ' . $status . "\r\nContent-Type: application/json\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n");
    for ($index = 0; $index < 32; ++$index) {
        $chunk = str_repeat('x', 64);
        fwrite($connection, dechex(strlen($chunk)) . "\r\n" . $chunk . "\r\n");
    }
    @fwrite($connection, "0\r\n\r\n");
} elseif ($mode === 'redirect') {
    fwrite($connection, "HTTP/1.1 302 Found\r\nLocation: /followed\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
} elseif ($mode === '429') {
    $response = '{"error":"rate_limited"}';
    fwrite($connection, "HTTP/1.1 429 Too Many Requests\r\nRetry-After: 120\r\nContent-Type: application/json\r\nContent-Length: " . strlen($response) . "\r\nConnection: close\r\n\r\n" . $response);
} else {
    $response = '{"accepted":true}';
    fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($response) . "\r\nConnection: close\r\n\r\n" . $response);
}
fclose($connection);

if ($mode === 'redirect') {
    $followed = @stream_socket_accept($server, 1);
    if (is_resource($followed)) {
        file_put_contents($directory . '/followed', 'followed');
        fclose($followed);
    }
}

fclose($server);
