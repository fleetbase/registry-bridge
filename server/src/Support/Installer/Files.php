<?php

namespace Fleetbase\RegistryBridge\Support\Installer;

/**
 * Small filesystem helpers (recursive copy, move and delete) that work
 * without the Laravel filesystem.
 */
class Files
{
    public static function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
            throw new \RuntimeException(sprintf('Could not create directory %s.', $path));
        }
    }

    public static function delete(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }

    public static function copy(string $from, string $to): void
    {
        if (is_file($from)) {
            static::ensureDirectory(dirname($to));
            if (!@copy($from, $to)) {
                throw new \RuntimeException(sprintf('Could not copy %s to %s.', $from, $to));
            }

            return;
        }

        static::ensureDirectory($to);
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($items as $item) {
            $target = $to . '/' . substr($item->getPathname(), strlen($from) + 1);
            if ($item->isDir()) {
                static::ensureDirectory($target);
            } elseif (!@copy($item->getPathname(), $target)) {
                throw new \RuntimeException(sprintf('Could not copy %s to %s.', $item->getPathname(), $target));
            }
        }
    }

    /**
     * Move a file or directory, falling back to copy and delete across devices
     * (a shared Docker volume is usually another device).
     */
    public static function move(string $from, string $to): void
    {
        static::delete($to);
        static::ensureDirectory(dirname($to));
        if (@rename($from, $to)) {
            return;
        }

        static::copy($from, $to);
        static::delete($from);
    }

    /**
     * Write a file atomically (temp file and rename in the same directory).
     */
    public static function write(string $path, string $contents): void
    {
        static::ensureDirectory(dirname($path));
        $temp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($temp, $contents) === false || !@rename($temp, $path)) {
            @unlink($temp);
            throw new \RuntimeException(sprintf('Could not write %s.', $path));
        }
    }

    /**
     * @return array<int, string> paths relative to $dir, with forward slashes
     */
    public static function listFiles(string $dir): array
    {
        $files = [];
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($items as $item) {
            if ($item->isFile() || $item->isLink()) {
                $files[] = str_replace('\\', '/', substr($item->getPathname(), strlen($dir) + 1));
            }
        }
        sort($files);

        return $files;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }
}
