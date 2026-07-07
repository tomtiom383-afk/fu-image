<?php

declare(strict_types=1);

namespace ImageHosting;

class Security
{
    public static function isValidExtension(string $ext, array $allowed): bool
    {
        $ext = strtolower(ltrim($ext, '.'));
        return in_array($ext, $allowed, true);
    }

    public static function isAllowedMimeType(string $mime, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($mime, $prefix)) {
                return true;
            }
        }
        return false;
    }

    public static function sanitizeFilename(string $filename): string
    {
        $filename = basename($filename);
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
        $filename = preg_replace('/\.{2,}/', '.', $filename);
        return $filename;
    }

    public static function isSafePath(string $path, string $base): bool
    {
        $realPath = realpath($path);
        $realBase = realpath($base);
        if ($realPath === false || $realBase === false) {
            return false;
        }
        return str_starts_with($realPath, $realBase . DIRECTORY_SEPARATOR) || $realPath === $realBase;
    }

    public static function sanitizeSvg(string $svg): bool
    {
        if (preg_match('/<script[\s\S]*?<\/script>/i', $svg) ||
            stripos($svg, 'javascript:') !== false) {
            return false;
        }

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadXML($svg, LIBXML_NONET);
        libxml_clear_errors();

        if (!$dom->documentElement) {
            return false;
        }

        $xpath = new \DOMXPath($dom);

        $blockedTags = ['script', 'foreignobject', 'foreignObject', 'animate', 'set', 'handler'];
        foreach ($blockedTags as $tag) {
            $nodes = $xpath->query("//*[local-name()='{$tag}']");
            if ($nodes && $nodes->length > 0) return false;
        }

        $allAttributes = $xpath->query('//@*');
        if ($allAttributes) {
            foreach ($allAttributes as $attr) {
                $name = strtolower($attr->name);
                if (str_starts_with($name, 'on') && $name !== 'one') return false;
                if (stripos($attr->value, 'javascript:') !== false) return false;
            }
        }

        $allElements = $xpath->query('//*');
        if ($allElements) {
            foreach ($allElements as $el) {
                $href = $el->getAttributeNS('http://www.w3.org/1999/xlink', 'href');
                $href = $el->getAttribute('href') ?: $href;
                if ($href !== '' && !str_starts_with($href, '#') && !str_starts_with($href, 'data:image/')) return false;
            }
        }

        return true;
    }

    public static function clientIp(): string
    {
        $headers = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                if (is_string($ip)) {
                    $parts = explode(',', $ip);
                    $first = trim($parts[0]);
                    if (filter_var($first, FILTER_VALIDATE_IP)) {
                        return $first;
                    }
                }
            }
        }
        return 'unknown';
    }

    public static function generateId(int $bytes = 4): string
    {
        return bin2hex(random_bytes($bytes));
    }

    public static function isHttpsRequest(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
            return true;
        }
        return (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
    }
}
