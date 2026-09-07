<?php

declare(strict_types=1);

namespace WeewxPhp\CoreUpdate;

/** A complete core release, excluding installation data and third-party packages. */
final class Package
{
    public const LIMIT = 16777216;
    public const FILE_LIMIT = 8388608;
    public const MARKERS = ['src/autoload.php', 'src/Version.php', 'src/CoreUpdate/Guard.php', 'public/index.php', 'public/admin/index.php', 'themes/basic/theme.php'];

    /** @param array<string, string> $files */
    private function __construct(public readonly string $version, public readonly array $files) {}

    public static function path(string $path): void
    {
        \WeewxPhp\Extension\Release::path($path);
        if (!in_array($path, ['frontend.php', 'LICENSE', 'bin/weewx-php', 'bin/worker.php', 'bin/ingest-router.php'], true)
            && preg_match('#^(?:src/|resources/|public/|themes/basic/).+\.(?:php|json|js|css|svg|png|ico|webp|woff2?|md)$#D', $path) !== 1
            && preg_match('#^public/assets/vendor/.+/(?:LICENSE|NOTICE)$#D', $path) !== 1) {
            throw new \InvalidArgumentException('File outside core update scope');
        }
    }

    public static function parse(string $json, string $version): self
    {
        if (strlen($json) > self::LIMIT) {
            throw new \InvalidArgumentException('Core package too large');
        }
        $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['schema'] ?? null) !== 1 || ($data['version'] ?? null) !== $version
            || !is_string($data['php'] ?? null) || preg_match('/^\d+\.\d+(?:\.\d+)?$/D', $data['php']) !== 1
            || !version_compare(PHP_VERSION, $data['php'], '>=') || !is_array($data['files'] ?? null)
            || count($data['files']) < count(self::MARKERS) || count($data['files']) > 2048
            || !is_array($data['requires'] ?? null) || !array_is_list($data['requires']) || count($data['requires']) > 32) {
            throw new \InvalidArgumentException('Invalid or incompatible core package');
        }
        foreach ($data['requires'] as $extension) {
            if (!is_string($extension) || preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $extension) !== 1 || !extension_loaded($extension)) {
                throw new \InvalidArgumentException('Missing required PHP extension');
            }
        }
        $files = [];
        $seen = [];
        $total = 0;
        foreach ($data['files'] as $path => $item) {
            if (!is_string($path) || !is_array($item) || !is_string($item['body'] ?? null) || !is_string($item['sha256'] ?? null)) {
                throw new \InvalidArgumentException('Invalid core file');
            }
            self::path($path);
            $key = strtolower($path);
            foreach (array_keys($seen) as $previous) {
                if ($key === $previous || str_starts_with($key, $previous . '/') || str_starts_with($previous, $key . '/')) {
                    throw new \InvalidArgumentException('Conflicting core paths');
                }
            }
            $seen[$key] = true;
            $body = base64_decode($item['body'], true);
            if ($body === false || strlen($body) > self::FILE_LIMIT || !hash_equals(hash('sha256', $body), $item['sha256'])) {
                throw new \InvalidArgumentException('Core file checksum mismatch');
            }
            $total += strlen($body);
            if ($total > self::FILE_LIMIT) {
                throw new \InvalidArgumentException('Core files too large');
            }
            if (str_ends_with($path, '.php') || $path === 'bin/weewx-php') {
                token_get_all($body, TOKEN_PARSE); // @phpstan-ignore function.resultUnused (TOKEN_PARSE validates syntax without executing the release.)
            }
            $files[$path] = $body;
        }
        foreach (self::MARKERS as $marker) {
            if (!isset($files[$marker])) {
                throw new \InvalidArgumentException('Incomplete core package');
            }
        }
        if (preg_match('/public const STRING = \'([0-9.a-z-]+)\';/', $files['src/Version.php'], $match) !== 1 || $match[1] !== $version) {
            throw new \InvalidArgumentException('Core version mismatch');
        }
        ksort($files);
        return new self($version, $files);
    }
}
