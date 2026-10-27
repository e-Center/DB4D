<?php
declare(strict_types=1);

namespace DB4D;

/** Bounded, exact stream I/O. Never retries a SQL operation or reconnects. */
final class Transport
{
    private float $deadline;
    private function __construct(private $stream, private float $timeout)
    {
        $this->startOperation();
    }

    public static function connect(string $host, int $port, float $timeout, ?float $readTimeout = null): self
    {
        if ($host === '' || !preg_match('/^[a-zA-Z0-9.:%_-]+$/D', $host) || $port < 1 || $port > 65535 || $timeout <= 0 || !is_finite($timeout)) {
            throw new DB4DException('Invalid 4D endpoint or timeout.', 'HY024');
        }
        $address = str_contains($host, ':') ? '[' . $host . ']' : $host;
        // Errors deliberately exclude endpoint credentials and operating-system messages.
        $stream = @stream_socket_client('tcp://' . $address . ':' . $port, $error, $description, $timeout);
        if ($stream === false) {
            throw new DB4DException('Cannot connect to the 4D SQL service.', '08001', $error);
        }
        stream_set_blocking($stream, true);
        return new self($stream, $readTimeout ?? $timeout);
    }

    public function startOperation(): void
    {
        $this->deadline = microtime(true) + $this->timeout;
    }

    private function arm(): void
    {
        if (!is_resource($this->stream)) {
            throw new DB4DException('The 4D connection is closed.', '08003');
        }
        $remaining = $this->deadline - microtime(true);
        if ($remaining <= 0) {
            $this->abort('HYT00');
        }
        $seconds = (int) $remaining;
        stream_set_timeout($this->stream, $seconds, max(1, (int) (($remaining - $seconds) * 1000000)));
    }

    public function write(string $bytes): void
    {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $this->arm();
            $written = @fwrite($this->stream, substr($bytes, $offset, 65536));
            if ($written === false || $written === 0) {
                $this->ioFailure();
            }
            $offset += $written;
        }
    }

    public function read(int $length): string
    {
        if ($length < 0) {
            throw new DB4DException('Invalid protocol length.', '08S01');
        }
        $bytes = '';
        while (strlen($bytes) < $length) {
            $this->arm();
            $chunk = @fread($this->stream, min(65536, $length - strlen($bytes)));
            if ($chunk === false || $chunk === '') {
                $this->ioFailure();
            }
            $bytes .= $chunk;
        }
        return $bytes;
    }

    public function line(int $maximum): string
    {
        $this->arm();
        $line = @fgets($this->stream, $maximum + 1);
        if ($line === false || $line === '') {
            $this->ioFailure();
        }
        if (!str_ends_with($line, "\r\n")) {
            $meta = stream_get_meta_data($this->stream);
            $this->abort($meta['timed_out'] ? 'HYT00' : (feof($this->stream) ? '08006' : '08S01'));
        }
        return substr($line, 0, -2);
    }

    private function ioFailure(): never
    {
        $timeout = is_resource($this->stream) && (stream_get_meta_data($this->stream)['timed_out'] ?? false);
        $this->abort($timeout ? 'HYT00' : '08006');
    }

    private function abort(string $state): never
    {
        $this->close();
        throw new DB4DException('4D transport interrupted; execution outcome may be unknown. No automatic retry.', $state);
    }

    public function isOpen(): bool { return is_resource($this->stream); }
    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
        $this->stream = null;
    }
    public function __destruct() { $this->close(); }
}
