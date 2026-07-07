<?php

declare(strict_types=1);

namespace ImageHosting;

use RuntimeException;

class Storage
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->config->ensureDirs();
    }

    public function dataDir(): string
    {
        return $this->config->dataDir();
    }

    public function metaDir(): string
    {
        return $this->config->metaDir();
    }

    public function generatePath(): string
    {
        $pattern = $this->config->get('upload.storage_path', 'Y/m/d');
        return date($pattern) . '/';
    }

    public function absolutePath(string $relativePath): string
    {
        $relativePath = ltrim($relativePath, '/');
        return $this->dataDir() . '/' . $relativePath;
    }

    public function ensureDir(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
            throw new RuntimeException("Unable to create directory: {$path}");
        }
    }

    /**
     * @param array<string, mixed> $file
     */
    public function moveUploadedFile(array $file, string $destinationPath): void
    {
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('Invalid uploaded file');
        }
        $dir = dirname($destinationPath);
        $this->ensureDir($dir);
        if (!move_uploaded_file($file['tmp_name'], $destinationPath)) {
            throw new RuntimeException('Failed to move uploaded file');
        }
    }

    public function deleteFile(string $relativePath): bool
    {
        $absolutePath = $this->absolutePath($relativePath);
        if (!file_exists($absolutePath)) {
            return true;
        }
        if (!Security::isSafePath($absolutePath, $this->dataDir())) {
            return false;
        }
        $result = unlink($absolutePath);
        $this->cleanupEmptyDirs(dirname($absolutePath));
        return $result;
    }

    public function cleanupEmptyDirs(string $dir): void
    {
        $dataDir = $this->dataDir();
        $dir = realpath($dir);
        $realBase = realpath($dataDir);
        if ($dir === false || $realBase === false) {
            return;
        }
        while ($dir !== $realBase && is_dir($dir) && $this->isDirEmpty($dir)) {
            rmdir($dir);
            $dir = dirname($dir);
        }
    }

    private function isDirEmpty(string $dir): bool
    {
        $files = scandir($dir);
        if ($files === false) {
            return true;
        }
        foreach ($files as $file) {
            if ($file !== '.' && $file !== '..') {
                return false;
            }
        }
        return true;
    }

}
