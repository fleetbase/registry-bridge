<?php

namespace Fleetbase\RegistryBridge\Support\Installer;

/**
 * Fetches release artifacts into the job's work directory and verifies their
 * checksums.
 *
 * HTTP downloads carry the headers `$headers` returns for their URL: the
 * instance token for the Registry Service's package routes (see
 * PackageAuth::downloadHeaders). Credentialed requests do not follow
 * redirects, so the token never reaches another host.
 */
class ArtifactDownloader
{
    /**
     * @param callable(string $disk, string $path): (resource|null)|null                      $diskReader  opens a stream on a filesystem disk
     * @param callable(string $url, string $target, array<string, string> $headers): void|null $httpFetcher downloads a URL to a file
     * @param callable(string $url): array<string, string>|null                               $headers     request headers for a URL
     */
    public function __construct(
        private readonly bool $requireChecksums = false,
        private $diskReader = null,
        private $httpFetcher = null,
        private $headers = null,
    ) {
    }

    /**
     * @return string the local path of the verified download
     */
    public function download(Artifact $artifact, string $directory): string
    {
        Files::ensureDirectory($directory);
        $target = $directory . '/' . $this->filename($artifact);
        $this->fetch($artifact->uri, $target);

        if ($artifact->checksum === null || $artifact->checksum === '') {
            if ($this->requireChecksums) {
                throw new \RuntimeException(sprintf('The %s artifact has no checksum and checksums are required.', $artifact->role));
            }

            return $target;
        }

        if (!Checksum::matches($target, $artifact->checksum)) {
            throw new ChecksumMismatchException(sprintf('Checksum mismatch for the %s artifact: expected %s, got %s.', $artifact->role, $artifact->checksum, Checksum::sha256($target)));
        }

        return $target;
    }

    private function fetch(string $uri, string $target): void
    {
        if (preg_match('#^disk://([^/]+)/(.+)$#', $uri, $match)) {
            if (!$this->diskReader) {
                throw new \RuntimeException('No filesystem disk reader is configured for disk:// artifacts.');
            }
            $stream = ($this->diskReader)($match[1], $match[2]);
            if (!is_resource($stream)) {
                throw new \RuntimeException(sprintf('Could not read %s.', $uri));
            }
            $this->writeStream($stream, $target);

            return;
        }

        if (preg_match('#^https?://#i', $uri)) {
            $headers = $this->headers ? ($this->headers)($uri) : [];
            if ($this->httpFetcher) {
                ($this->httpFetcher)($uri, $target, $headers);

                return;
            }
            $lines  = array_map(fn ($name, $value) => $name . ': ' . $value, array_keys($headers), $headers);
            $stream = @fopen($uri, 'rb', false, stream_context_create(['http' => ['timeout' => 300, 'follow_location' => $headers === [] ? 1 : 0, 'header' => $lines]]));
            if (!is_resource($stream)) {
                throw new \RuntimeException(sprintf('Could not download %s.', $uri));
            }
            $this->writeStream($stream, $target);

            return;
        }

        $path = str_starts_with($uri, 'file://') ? substr($uri, 7) : $uri;
        if (!str_starts_with($path, '/') || !is_file($path)) {
            throw new \RuntimeException(sprintf('Artifact %s does not exist.', $uri));
        }
        Files::copy($path, $target);
    }

    /**
     * @param resource $stream
     */
    private function writeStream($stream, string $target): void
    {
        $out = fopen($target, 'wb');
        if ($out === false) {
            throw new \RuntimeException(sprintf('Could not write %s.', $target));
        }
        stream_copy_to_stream($stream, $out);
        fclose($out);
        fclose($stream);
    }

    private function filename(Artifact $artifact): string
    {
        $name = $artifact->filename ?: basename((string) parse_url($artifact->uri, PHP_URL_PATH));
        $name = preg_replace('/[^A-Za-z0-9._-]/', '-', $name) ?: 'artifact';

        return $artifact->role . '-' . ltrim($name, '.');
    }
}
