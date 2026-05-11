<?php

declare(strict_types=1);

class FileCache
{
    private string $dir;
    private int $defaultTtl;

    public function __construct(string $dir, int $defaultTtl = 300)
    {
        $this->dir = rtrim($dir, DIRECTORY_SEPARATOR);
        $this->defaultTtl = $defaultTtl;

        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0755, true);
        }
    }

    public function get(string $key): mixed
    {
        $file = $this->pathFor($key);

        if (!is_file($file)) {
            return null;
        }

        $handle = fopen($file, 'rb');
        if ($handle === false) {
            return null;
        }

        $expiryLine = fgets($handle);
        $json = stream_get_contents($handle);
        fclose($handle);

        if ($expiryLine === false || $json === false) {
            return null;
        }

        $expiresAt = (int) trim($expiryLine);
        if ($expiresAt > 0 && $expiresAt < time()) {
            @unlink($file);
            return null;
        }

        $data = json_decode($json, true);
        return json_last_error() === JSON_ERROR_NONE ? $data : null;
    }

    public function set(string $key, mixed $data, ?int $ttl = null): void
    {
        $file = $this->pathFor($key);
        $expiresAt = time() + ($ttl ?? $this->defaultTtl);
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return;
        }

        file_put_contents($file, $expiresAt . PHP_EOL . $json, LOCK_EX);
    }

    public function invalidate(string $key): void
    {
        $file = $this->pathFor($key);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    private function pathFor(string $key): string
    {
        $safeKey = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $key) ?: 'cache';
        return $this->dir . DIRECTORY_SEPARATOR . 'ghl_' . $safeKey . '.json';
    }
}

