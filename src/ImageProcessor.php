<?php

declare(strict_types=1);

namespace ImageHosting;

use RuntimeException;

class ImageProcessor
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    public function validate(array $file): array
    {
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('文件上传失败');
        }

        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $allowedExts = $this->config->get('upload.allowed_extensions', []);
        if (!Security::isValidExtension($ext, $allowedExts)) {
            throw new RuntimeException('不支持的文件格式：' . $ext);
        }

        $maxSize = (int) $this->config->get('upload.max_size', 10485760);
        if ((int) ($file['size'] ?? 0) > $maxSize) {
            throw new RuntimeException('文件超过大小限制：' . $this->formatBytes($maxSize));
        }

        $mime = (string) ($file['type'] ?? '');
        $allowedPrefixes = $this->config->get('upload.allowed_mime_prefixes', ['image/']);
        if (!Security::isAllowedMimeType($mime, $allowedPrefixes)) {
            throw new RuntimeException('不支持的文件类型');
        }

        $realMime = mime_content_type($file['tmp_name']);
        if ($realMime === false || !Security::isAllowedMimeType($realMime, $allowedPrefixes)) {
            throw new RuntimeException('文件内容与实际类型不符');
        }

        // SVG script check
        if ($ext === 'svg' || $realMime === 'image/svg+xml') {
            $content = file_get_contents($file['tmp_name']);
            if ($content !== false && !Security::sanitizeSvg($content)) {
                throw new RuntimeException('SVG 包含非法内容');
            }
        }

        $imageInfo = @getimagesize($file['tmp_name']);
        if ($imageInfo !== false) {
            [$width, $height] = $imageInfo;
            $maxW = (int) $this->config->get('upload.image_max_width', 10240);
            $maxH = (int) $this->config->get('upload.image_max_height', 10240);
            $minW = (int) $this->config->get('upload.image_min_width', 5);
            $minH = (int) $this->config->get('upload.image_min_height', 5);
            if ($width > $maxW || $height > $maxH) {
                throw new RuntimeException("图片尺寸过大，最大支持 {$maxW}x{$maxH}");
            }
            if ($width < $minW || $height < $minH) {
                throw new RuntimeException('图片尺寸过小');
            }
        }

        return [
            'ext' => $ext,
            'mime' => $realMime,
            'width' => $imageInfo[0] ?? 0,
            'height' => $imageInfo[1] ?? 0,
        ];
    }

    public function process(string $sourcePath, string $ext, ?string $convertTo = null): array
    {
        $imageInfo = @getimagesize($sourcePath);
        $width = $imageInfo[0] ?? 0;
        $height = $imageInfo[1] ?? 0;

        $convertTo = $this->resolveConvertTo($convertTo, $ext);
        if ($convertTo && $convertTo !== $ext) {
            $this->convertImage($sourcePath, $ext, $convertTo);
            $ext = $convertTo;
            $imageInfo = @getimagesize($sourcePath);
            $width = $imageInfo[0] ?? $width;
            $height = $imageInfo[1] ?? $height;
        }

        if ($this->config->get('upload.watermark.enabled', false)) {
            $this->applyWatermark($sourcePath);
        }

        return [
            'ext' => $ext,
            'width' => $width,
            'height' => $height,
        ];
    }

    private function resolveConvertTo(?string $convertTo, string $ext): ?string
    {
        if ($convertTo === null || $convertTo === '') {
            return $this->config->get('upload.convert_webp', false) ? 'webp' : null;
        }
        $convertTo = strtolower($convertTo);
        if (!in_array($convertTo, ['webp', 'jpg', 'jpeg', 'png'], true)) {
            return null;
        }
        if (in_array($ext, ['gif', 'webp'], true)) {
            return null;
        }
        return $convertTo === 'jpeg' ? 'jpg' : $convertTo;
    }

    private function convertImage(string $path, string $fromExt, string $toExt): void
    {
        $img = $this->createImage($path, $fromExt);
        if (!$img) {
            return;
        }
        $newPath = preg_replace('/\.[^.]+$/', '.' . $toExt, $path);
        $quality = (int) $this->config->get('upload.' . $toExt . '_quality', 80);
        $saved = false;
        switch ($toExt) {
            case 'jpg':
                $saved = imagejpeg($img, $newPath, $quality);
                break;
            case 'png':
                $saved = imagepng($img, $newPath, (int) round((100 - $quality) / 11.2));
                break;
            case 'webp':
                $saved = imagewebp($img, $newPath, $quality);
                break;
        }
        imagedestroy($img);
        if ($saved && $newPath !== $path && file_exists($newPath)) {
            unlink($path);
            rename($newPath, $path);
        }
    }

    /**
     * @return resource|false
     */
    private function createImage(string $path, string $ext)
    {
        $info = @getimagesize($path);
        $mime = $info['mime'] ?? '';
        switch ($mime) {
            case 'image/jpeg':
                return imagecreatefromjpeg($path);
            case 'image/png':
                $img = @imagecreatefrompng($path);
                if ($img) {
                    imagealphablending($img, false);
                    imagesavealpha($img, true);
                }
                return $img;
            case 'image/gif':
                return imagecreatefromgif($path);
            case 'image/webp':
                return imagecreatefromwebp($path);
            case 'image/bmp':
                return imagecreatefrombmp($path) ?: false;
            default:
                return false;
        }
    }

    private function applyWatermark(string $path): void
    {
        $img = $this->createImage($path, '');
        if (!$img) {
            return;
        }
        $text = (string) $this->config->get('upload.watermark.text', '');
        if ($text === '') {
            imagedestroy($img);
            return;
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $colorParts = array_map('intval', explode(',', (string) $this->config->get('upload.watermark.color', '255,255,255')));
        $r = $colorParts[0] ?? 255;
        $g = $colorParts[1] ?? 255;
        $b = $colorParts[2] ?? 255;
        $alpha = (int) $this->config->get('upload.watermark.alpha', 30);
        $alpha = (int) (127 * (1 - ($alpha / 100)));
        $color = imagecolorallocatealpha($img, $r, $g, $b, $alpha);
        $fontSize = (int) $this->config->get('upload.watermark.size', 5);
        $x = max(10, $w - 200);
        $y = max(10, $h - 30);
        imagestring($img, $fontSize, $x, $y, $text, $color);
        $this->saveImage($img, $path);
        imagedestroy($img);
    }

    /**
     * @param resource $img
     */
    private function saveImage($img, string $path): void
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        switch ($ext) {
            case 'jpg':
            case 'jpeg':
                imagejpeg($img, $path, (int) $this->config->get('upload.jpeg_quality', 80));
                break;
            case 'png':
                imagepng($img, $path, (int) round((100 - (int) $this->config->get('upload.jpeg_quality', 80)) / 11.2));
                break;
            case 'webp':
                imagewebp($img, $path, (int) $this->config->get('upload.webp_quality', 80));
                break;
            case 'gif':
                imagegif($img, $path);
                break;
        }
    }

    public function generateThumbnail(string $sourcePath): ?string
    {
        if (!$this->config->get('upload.thumbnail.enabled', false)) {
            return null;
        }
        $img = $this->createImage($sourcePath, '');
        if (!$img) {
            return null;
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $tw = (int) $this->config->get('upload.thumbnail.width', 258);
        $th = (int) $this->config->get('upload.thumbnail.height', 258);
        $thumb = imagecreatetruecolor($tw, $th);
        if (!$thumb) {
            imagedestroy($img);
            return null;
        }
        $srcRatio = $w / $h;
        $dstRatio = $tw / $th;
        if ($srcRatio > $dstRatio) {
            $newH = $tw / $srcRatio;
            $newW = $tw;
            $dstY = ($th - (int) $newH) / 2;
            $dstX = 0;
        } else {
            $newW = $th * $srcRatio;
            $newH = $th;
            $dstX = ($tw - (int) $newW) / 2;
            $dstY = 0;
        }
        imagecopyresampled($thumb, $img, (int) $dstX, (int) $dstY, 0, 0, (int) $newW, (int) $newH, $w, $h);
        imagedestroy($img);

        $thumbPath = preg_replace('/\.[^.]+$/', '.thumb.webp', $sourcePath);
        imagewebp($thumb, $thumbPath, (int) $this->config->get('upload.webp_quality', 80));
        imagedestroy($thumb);
        return $thumbPath;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = (int) floor(log($bytes, 1024));
        return round($bytes / (1024 ** $i), 2) . ' ' . $units[$i];
    }
}
