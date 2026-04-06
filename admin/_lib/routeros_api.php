<?php
declare(strict_types=1);

class RouterOSAPI
{
    private $socket = null;
    private int $timeout = 3;

    public function connect(string $ip, string $username, string $password, int $port = 8728, int $timeout = 3): bool
    {
        $this->timeout = $timeout;
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($ip, $port, $errno, $errstr, $timeout);
        if ($socket === false) {
            return false;
        }
        stream_set_timeout($socket, $timeout);
        $this->socket = $socket;

        $this->writeWord('/login', false);
        $this->writeWord('=name=' . $username, false);
        $this->writeWord('=password=' . $password, true);

        $response = $this->readResponse();
        foreach ($response as $sentence) {
            if (($sentence[0] ?? '') === '!done') {
                return true;
            }
            if (($sentence[0] ?? '') === '!trap') {
                $this->disconnect();
                return false;
            }
        }
        $this->disconnect();
        return false;
    }

    public function disconnect(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->socket = null;
    }

    public function isConnected(): bool
    {
        return is_resource($this->socket);
    }

    public function comm(string $command, array $arguments = []): array
    {
        if (!$this->isConnected()) {
            throw new RuntimeException('Not connected.');
        }

        $this->writeWord($command, false);
        foreach ($arguments as $key => $value) {
            if (is_int($key)) {
                $this->writeWord((string)$value, false);
                continue;
            }
            $this->writeWord('=' . $key . '=' . (string)$value, false);
        }
        $this->writeWord('', true);

        $sentences = $this->readResponse();
        $rows = [];
        foreach ($sentences as $sentence) {
            $type = $sentence[0] ?? '';
            if ($type === '!re') {
                $rows[] = $this->sentenceToAssoc($sentence);
            }
        }
        return $rows;
    }

    public function commOne(string $command, array $arguments = []): ?array
    {
        $rows = $this->comm($command, $arguments);
        if (count($rows) === 0) {
            return null;
        }
        return $rows[0];
    }

    private function sentenceToAssoc(array $sentence): array
    {
        $row = [];
        foreach ($sentence as $i => $word) {
            if ($i === 0) {
                continue;
            }
            if (!is_string($word)) {
                continue;
            }
            if ($word === '') {
                continue;
            }
            if ($word[0] !== '=') {
                continue;
            }
            $parts = explode('=', $word, 3);
            if (count($parts) === 3) {
                $row[$parts[1]] = $parts[2];
            }
        }
        return $row;
    }

    private function readResponse(): array
    {
        $sentences = [];
        while (true) {
            $sentence = [];
            while (true) {
                $word = $this->readWord();
                if ($word === null) {
                    throw new RuntimeException('Connection closed.');
                }
                if ($word === '') {
                    break;
                }
                $sentence[] = $word;
            }
            if (count($sentence) > 0) {
                $sentences[] = $sentence;
            }
            $type = $sentence[0] ?? '';
            if ($type === '!done' || $type === '!trap') {
                break;
            }
        }
        return $sentences;
    }

    private function readWord(): ?string
    {
        if (!is_resource($this->socket)) {
            return null;
        }

        $len = $this->readLength();
        if ($len === null) {
            return null;
        }
        if ($len === 0) {
            return '';
        }

        $word = '';
        $remaining = $len;
        while ($remaining > 0) {
            $chunk = fread($this->socket, $remaining);
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->socket);
                if (($meta['timed_out'] ?? false) === true) {
                    throw new RuntimeException('RouterOS API timed out.');
                }
                return null;
            }
            $word .= $chunk;
            $remaining = $len - strlen($word);
        }
        return $word;
    }

    private function readLength(): ?int
    {
        if (!is_resource($this->socket)) {
            return null;
        }
        $c = ord($this->readByte() ?? "\0");
        if ($c < 0x80) {
            return $c;
        }
        if (($c & 0xC0) === 0x80) {
            $len = (($c & 0x3F) << 8) + ord($this->readByte() ?? "\0");
            return $len;
        }
        if (($c & 0xE0) === 0xC0) {
            $len = (($c & 0x1F) << 16) + (ord($this->readByte() ?? "\0") << 8) + ord($this->readByte() ?? "\0");
            return $len;
        }
        if (($c & 0xF0) === 0xE0) {
            $len = (($c & 0x0F) << 24) + (ord($this->readByte() ?? "\0") << 16) + (ord($this->readByte() ?? "\0") << 8) + ord($this->readByte() ?? "\0");
            return $len;
        }
        if (($c & 0xF8) === 0xF0) {
            $len = ord($this->readByte() ?? "\0") << 24;
            $len += ord($this->readByte() ?? "\0") << 16;
            $len += ord($this->readByte() ?? "\0") << 8;
            $len += ord($this->readByte() ?? "\0");
            return $len;
        }
        return null;
    }

    private function readByte(): ?string
    {
        if (!is_resource($this->socket)) {
            return null;
        }
        $byte = fread($this->socket, 1);
        if ($byte === false || $byte === '') {
            $meta = stream_get_meta_data($this->socket);
            if (($meta['timed_out'] ?? false) === true) {
                throw new RuntimeException('RouterOS API timed out.');
            }
            return null;
        }
        return $byte;
    }

    private function writeWord(string $word, bool $end): void
    {
        if (!is_resource($this->socket)) {
            throw new RuntimeException('Not connected.');
        }
        $this->writeLength(strlen($word));
        if ($word !== '') {
            fwrite($this->socket, $word);
        }
        if ($end) {
            $this->writeLength(0);
            fwrite($this->socket, '');
        }
    }

    private function writeLength(int $len): void
    {
        if ($len < 0x80) {
            fwrite($this->socket, chr($len));
            return;
        }
        if ($len < 0x4000) {
            $len |= 0x8000;
            fwrite($this->socket, chr(($len >> 8) & 0xFF) . chr($len & 0xFF));
            return;
        }
        if ($len < 0x200000) {
            $len |= 0xC00000;
            fwrite(
                $this->socket,
                chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF)
            );
            return;
        }
        if ($len < 0x10000000) {
            $len |= 0xE0000000;
            fwrite(
                $this->socket,
                chr(($len >> 24) & 0xFF) . chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF)
            );
            return;
        }
        fwrite(
            $this->socket,
            chr(0xF0) . chr(($len >> 24) & 0xFF) . chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF)
        );
    }
}

