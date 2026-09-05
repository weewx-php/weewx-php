<?php

declare(strict_types=1);

namespace WeewxPhp\Config;

/**
 * The whole configuration, read and checked. Values have their types and
 * paths are absolute; a file that does not hold together is refused with a
 * message naming the setting, and what merely looks odd is a warning.
 */
final class Config
{
    /**
     * @param array<string, StationConfig> $stations By id, in file order.
     * @param array<string, ArchiveConfig> $archives By id, in file order.
     * @param list<string> $warnings What `check-config` should show and a run should log once.
     * @param array<string, UploadConfig> $uploads By id, in file order.
     * @param array<string, string> $measurements Observation name to measurement kind.
     * @param array<string, array<string, \WeewxPhp\Measurement\Source>> $sources Station/native field definitions.
     */
    public function __construct(
        public readonly Settings $settings,
        public readonly array $stations,
        public readonly array $archives,
        public readonly array $warnings,
        public readonly array $uploads = [],
        public readonly IngestConfig $ingest = new IngestConfig(),
        public readonly array $measurements = [],
        public readonly array $sources = [],
    ) {}

    /**
     * @throws ConfigError If the file does not exist, cannot be parsed, or does not hold together.
     */
    public static function load(string $path): self
    {
        $file = ConfFile::read($path);
        $real = realpath($path);
        $directory = dirname($real === false ? $path : $real);
        return ConfigReader::read($file, $directory);
    }

    public function station(string $id): ?StationConfig
    {
        return $this->stations[$id] ?? null;
    }

    public function archive(string $id): ?ArchiveConfig
    {
        return $this->archives[$id] ?? null;
    }

    public function upload(string $id): ?UploadConfig
    {
        return $this->uploads[$id] ?? null;
    }

    /** @return array<string, int> */
    public function intervals(): array
    {
        $result = [];
        foreach ($this->archives as $id => $archive) {
            if ($archive->enabled) {
                $result[$id] = $archive->interval($this->settings);
            }
        }
        return $result;
    }
}
