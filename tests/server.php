<?php
declare(strict_types=1);

// Independent 4D wire peer. No DB4D classes, business database or external socket.
if (PHP_SAPI !== 'cli') { exit(1); }
$steps = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$server) { exit(2); }
echo stream_socket_get_name($server, false), "\n"; flush();
$stream = stream_socket_accept($server, 5);
if (!$stream) { exit(3); }
stream_set_timeout($stream, 3);
$lastId = 0;
try {
    foreach ($steps as $step) {
        $line = fgets($stream);
        if ($line === false || !preg_match('/^([0-9]+) ([A-Z-]+)\r\n$/D', $line, $match)) { throw new RuntimeException('Missing command'); }
        $id = (int)$match[1];
        if ($id <= $lastId || $match[2] !== $step['command']) { throw new RuntimeException('Wrong command or reused identifier'); }
        $lastId = $id; $headers = [];
        while (($line = fgets($stream)) !== "\r\n") {
            if ($line === false) { throw new RuntimeException('Truncated request'); }
            [$key, $value] = explode(':', trim($line), 2); $headers[strtolower($key)] = trim($value);
        }
        if (isset($step['sql']) && base64_decode($headers['statement-base64'] ?? '', true) !== $step['sql']) { throw new RuntimeException('SQL bytes changed'); }
        foreach ($step['headers'] ?? [] as $key => $value) {
            if (($headers[strtolower($key)] ?? null) !== $value) { throw new RuntimeException('Wrong request header'); }
        }
        if (isset($step['sleep_ms'])) { usleep($step['sleep_ms'] * 1000); }
        if ($step['disconnect'] ?? false) { fclose($stream); $stream = null; break; }
        $response = sprintf("%03d %s\r\n", ($step['wrong_id'] ?? false) ? $id + 1 : $id, $step['status'] ?? 'OK');
        foreach ($step['response'] ?? [] as $key => $value) { $response .= $key . ': ' . $value . "\r\n"; }
        $response .= "\r\n" . base64_decode($step['body'] ?? '');
        if (isset($step['truncate'])) { $response = substr($response, 0, $step['truncate']); }
        $offset = 0;
        while ($offset < strlen($response)) {
            $chunk = ($step['fragment'] ?? false) ? substr($response, $offset, 3) : substr($response, $offset);
            $n = @fwrite($stream, $chunk);
            if (!$n) { break; }
            $offset += $n;
            if ($step['fragment'] ?? false) { usleep(100); }
        }
        if (isset($step['truncate'])) { fclose($stream); $stream = null; break; }
    }
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(4); }
if (is_resource($stream)) { fclose($stream); }
fclose($server);
