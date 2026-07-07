<?php

declare(strict_types=1);

namespace ImageHosting;

use RuntimeException;

class Config
{
    private static ?self $instance = null;

    /** @var array<string, mixed> */
    private array $data;

    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
        if (!file_exists($path)) {
            throw new RuntimeException("Config file not found: {$path}");
        }
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("Unable to read config file: {$path}");
        }
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Invalid JSON in config file: {$path}");
        }
        $this->data = $decoded;
    }

    public static function load(string $path): self
    {
        if (self::$instance === null) {
            self::$instance = new self($path);
        }
        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        $keys = explode('.', $key);
        $value = $this->data;
        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return $default;
            }
            $value = $value[$k];
        }
        return $value;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function dataDir(): string
    {
        return rtrim($this->get('upload.data_dir', '/data/images'), '/');
    }

    public function metaDir(): string
    {
        return rtrim($this->get('upload.meta_dir', '/data/meta'), '/');
    }

    public function indexFile(): string
    {
        return $this->metaDir() . '/index.json';
    }

    public function baseUrl(): string
    {
        return rtrim($this->get('site.base_url', ''), '/');
    }

    public function ensureDirs(): void
    {
        foreach ([$this->dataDir(), $this->metaDir()] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException("Unable to create directory: {$dir}");
            }
        }
    }
}
