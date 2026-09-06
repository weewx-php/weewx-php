<?php

declare(strict_types=1);

namespace WeewxPhp\Extension;

use Throwable;
use WeewxPhp\Archive\Budget;
use WeewxPhp\Config\ArchiveConfig;
use WeewxPhp\Config\Config;
use WeewxPhp\Frontend\QueryError;
use WeewxPhp\Frontend\Report;
use WeewxPhp\Frontend\Series;
use WeewxPhp\Frontend\Value;
use WeewxPhp\Log\Logger;
use WeewxPhp\Tick\Lock;
use WeewxPhp\Upload\Http\BudgetedHttpClient;
use WeewxPhp\Upload\Http\HttpClient;

final class Registry
{
    /** @var array<string, Registration> */
    private array $packages = [];
    /** @var array<string, string> */
    private array $errors = [];

    public function __construct(private readonly Config $config)
    {
        foreach ($config->extensions as $id => $definition) {
            try {
                $register = self::entry($definition->entry);
                if (!is_callable($register)) {
                    throw new \RuntimeException('Extension entry must return a registration callable');
                }
                $registration = new Registration();
                $register($registration, $definition->options);
                $this->packages[$id] = $registration;
            } catch (Throwable $error) {
                $this->errors[$id] = $error->getMessage();
            }
        }
    }

    /** @return mixed Executed in a scope without application objects. */
    private static function entry(string $path): mixed
    {
        $local = realpath($path);
        if ($local === false || !is_file($local) || !is_readable($local)) {
            throw new \RuntimeException('Extension entry is not a readable local file');
        }
        return require $local;
    }

    public function has(string $tag): bool
    {
        [$id, $name] = array_pad(explode('.', $tag, 2), 2, '');
        return isset($this->packages[$id]) && isset($this->packages[$id]->tags()[$name]);
    }

    /** @return list<string> */
    public function tags(): array
    {
        $names = [];
        foreach ($this->packages as $id => $package) {
            foreach (array_keys($package->tags()) as $name) {
                $names[] = $id . '.' . $name;
            }
        }
        return $names;
    }

    /** @param array<string, mixed> $options */
    public function read(string $tag, ArchiveConfig $archive, int $now, array $options): Value|Series|Report
    {
        if (!$this->has($tag)) {
            throw new QueryError('Unknown or unavailable extension tag: ' . $tag);
        }
        [$id, $name] = explode('.', $tag, 2);
        $reader = $this->packages[$id]->tags()[$name];
        return $reader($this->context($id, $archive, $now), $options);
    }

    private function context(string $id, ArchiveConfig $archive, int $now): Context
    {
        return new Context($archive, $this->config->settings->dataDir . '/extensions/' . $id . '/' . hash('sha256', $archive->id), $now, $this->config->extensions[$id]->optionsFor($archive->id));
    }

    /** Independent writer locks also serialize synchronous ticks and detached workers.
     * @return array<string, array<string, int|string>>
     */
    public function run(Budget $budget, int $now, HttpClient $http, Logger $log): array
    {
        $results = [];
        foreach ($this->errors as $id => $message) {
            $log->warning('extension ' . $id . ': ' . $message);
            $results[$id] = ['status' => 'error'];
        }
        foreach ($this->packages as $id => $package) {
            $worker = $package->task();
            if ($worker === null) {
                continue;
            }
            foreach ($this->config->archives as $archive) {
                if (!$archive->enabled) {
                    continue;
                }
                $key = $id . '/' . $archive->id;
                if ($budget->timeLeft() < 2) {
                    $results[$key] = ['status' => 'budget'];
                    continue;
                }
                $lock = null;
                try {
                    $context = $this->context($id, $archive, $now);
                    if (!is_dir($context->directory) && !mkdir($context->directory, 0775, true) && !is_dir($context->directory)) {
                        throw new \RuntimeException('Cannot create extension data directory');
                    }
                    $lock = Lock::tryAcquire($context->directory . '/worker.lock');
                    $results[$key] = $lock === null ? ['status' => 'busy'] : $worker($context, $budget, new BudgetedHttpClient($http, $budget));
                } catch (Throwable $error) {
                    $log->warning('extension ' . $key . ': ' . $error->getMessage());
                    $results[$key] = ['status' => 'error'];
                } finally {
                    $lock?->release();
                }
            }
        }
        return $results;
    }
}
