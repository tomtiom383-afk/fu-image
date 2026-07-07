<?php

declare(strict_types=1);

namespace ImageHosting;

use RuntimeException;

class UploadHandler
{
    private Config $config;
    private Storage $storage;
    private ImageIndex $index;
    private ImageProcessor $processor;

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->storage = new Storage($config);
        $this->index = new ImageIndex($this->storage, $config);
        $this->processor = new ImageProcessor($config);
    }

    /**
     * @param array<string, mixed> $file
     */
    public function handle(array $file, ?string $convertTo = null): array
    {
        $validation = $this->processor->validate($file);
        $ext = $validation['ext'];
        $originalName = Security::sanitizeFilename((string) ($file['name'] ?? 'image'));

        $id = Security::generateId(4);
        $relativeDir = $this->storage->generatePath();
        $relativePath = $relativeDir . $id . '.' . $ext;
        $absolutePath = $this->storage->absolutePath($relativePath);

        $this->storage->moveUploadedFile($file, $absolutePath);

        $processed = $this->processor->process($absolutePath, $ext, $convertTo);
        $finalExt = $processed['ext'];

        // If extension changed during conversion, update path
        if ($finalExt !== $ext) {
            $newRelativePath = $relativeDir . $id . '.' . $finalExt;
            $newAbsolutePath = $this->storage->absolutePath($newRelativePath);
            rename($absolutePath, $newAbsolutePath);
            $relativePath = $newRelativePath;
            $absolutePath = $newAbsolutePath;
        }

        $size = filesize($absolutePath) ?: 0;
        $width = $processed['width'];
        $height = $processed['height'];

        $record = [
            'id' => $id,
            'filename' => $originalName,
            'path' => $relativePath,
            'url' => $this->imageUrl($relativePath),
            'thumb' => $this->imageUrl($relativePath),
            'delete_url' => $this->baseUrl() . '/api/v1/images.php?id=' . $id,
            'size' => $size,
            'width' => $width,
            'height' => $height,
            'created_at' => date('c'),
            'ip' => Security::clientIp(),
        ];

        $record['links'] = $this->formatLinks($record);

        $this->index->add($record);

        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    public function list(int $page, int $limit): array
    {
        $result = $this->index->list($page, $limit);
        $result['items'] = array_map(function (array $item): array {
            if (!isset($item['links'])) {
                $item['links'] = $this->formatLinks($item);
            }
            return $item;
        }, $result['items']);
        return $result;
    }

    public function delete(string $id): bool
    {
        $record = $this->index->find($id);
        if (!$record) {
            return false;
        }
        $this->storage->deleteFile($record['path']);
        $this->index->remove($id);
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        return $this->index->stats();
    }

    private function imageUrl(string $relativePath): string
    {
        return $this->baseUrl() . '/images/' . ltrim($relativePath, '/');
    }

    private function baseUrl(): string
    {
        $configured = $this->config->baseUrl();
        if ($configured !== '') {
            return $configured;
        }
        $scheme = Security::isHttpsRequest() ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host;
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, string>
     */
    private function formatLinks(array $record): array
    {
        $url = $record['url'];
        $name = $record['filename'];
        return [
            'url' => $url,
            'markdown' => "![{$name}]({$url})",
            'html' => "<img src=\"{$url}\" alt=\"{$name}\" />",
            'bbcode' => "[img]{$url}[/img]",
            'thumb' => $record['thumb'] ?? $url,
            'delete_url' => $record['delete_url'] ?? '',
        ];
    }
}
