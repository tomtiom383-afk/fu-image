<?php

declare(strict_types=1);

namespace ImageHosting;

class ImageIndex
{
    private Storage $storage;

    private string $indexFile;

    public function __construct(Storage $storage, Config $config)
    {
        $this->storage = $storage;
        $this->indexFile = $config->indexFile();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function read(): array
    {
        $cacheKey = 'imgidx_' . md5($this->indexFile);
        if (function_exists('apcu_fetch')) {
            $cached = apcu_fetch($cacheKey);
            if ($cached !== false) return $cached;
        }

        if (!file_exists($this->indexFile)) {
            return [];
        }
        $content = file_get_contents($this->indexFile);
        if ($content === false) {
            return [];
        }
        $data = json_decode($content, true);
        $data = is_array($data) ? $data : [];

        if (function_exists('apcu_store')) {
            apcu_store($cacheKey, $data, 5);
        }

        return $data;
    }

    /**
     * @param array<int, array<string, mixed>> $index
     */
    public function write(array $index): bool
    {
        $this->storage->ensureDir(dirname($this->indexFile));
        $tmp = $this->indexFile . '.tmp';
        $json = json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            return false;
        }
        if (rename($tmp, $this->indexFile)) {
            if (function_exists('apcu_delete')) {
                apcu_delete('imgidx_' . md5($this->indexFile));
            }
            return true;
        }
        return false;
    }

    /**
     * @param array<string, mixed> $record
     */
    public function add(array $record): bool
    {
        $index = $this->read();
        array_unshift($index, $record);
        return $this->write($index);
    }

    public function remove(string $id): bool
    {
        $index = $this->read();
        $found = false;
        foreach ($index as $key => $item) {
            if (($item['id'] ?? '') === $id) {
                unset($index[$key]);
                $found = true;
            }
        }
        if (!$found) {
            return false;
        }
        return $this->write(array_values($index));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        foreach ($this->read() as $item) {
            if (($item['id'] ?? '') === $id) {
                return $item;
            }
        }
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function list(int $page, int $limit): array
    {
        $index = $this->read();
        $total = count($index);
        $offset = ($page - 1) * $limit;
        $items = array_slice($index, $offset, $limit);
        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int) max(1, ceil($total / $limit)),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        $index = $this->read();
        $total = count($index);
        $today = date('Y-m-d');
        $todayCount = 0;
        $totalSize = 0;
        foreach ($index as $item) {
            $createdAt = (string) ($item['created_at'] ?? '');
            if (str_starts_with($createdAt, $today)) {
                $todayCount++;
            }
            $totalSize += (int) ($item['size'] ?? 0);
        }
        return [
            'total_images' => $total,
            'daily_uploads' => $todayCount,
            'total_size' => $totalSize,
        ];
    }
}
