<?php

namespace Fleetbase\RegistryBridge\Support\Installer;

use Illuminate\Contracts\Cache\Repository;

/**
 * Installer job records and the instance's queue order, kept in the cache the
 * web and queue processes share (Redis in the Docker images).
 *
 * A record: id, action, extension {uuid, id, name}, version, company, user,
 * status (queued, running, interrupted, completed, failed), stage, step,
 * progress, message, failed_stage, log, result, created_at, started_at,
 * finished_at, heartbeat_at. `position` is computed on read: the number of
 * jobs ahead of this one (0 = running or next).
 */
class InstallerJobStore
{
    public const PREFIX = 'registry-installer:';

    public const FINISHED = ['completed', 'failed'];

    public function __construct(private readonly Repository $cache, private readonly int $ttl = 604800)
    {
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @return array<string, mixed>
     */
    public function create(array $attributes): array
    {
        $record = [
            'id'         => self::newId(),
            'status'     => 'queued',
            'stage'      => null,
            'step'       => null,
            'progress'   => 0,
            'message'    => null,
            'result'     => null,
            'created_at' => date(DATE_ATOM),
            ...$attributes,
        ];
        $this->put($record);

        return $record;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $id): ?array
    {
        $record = $this->cache->get($this->key($id));

        return is_array($record) ? $record : null;
    }

    /**
     * The record with its current queue position.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $record = $this->get($id);
        if ($record === null) {
            return null;
        }
        $record['position'] = $this->position($id);

        return $record;
    }

    /**
     * @param array<string, mixed> $patch
     *
     * @return array<string, mixed>|null
     */
    public function update(string $id, array $patch): ?array
    {
        $record = $this->get($id);
        if ($record === null) {
            return null;
        }
        $record = [...$record, ...$patch];
        $this->put($record);

        return $record;
    }

    /**
     * @param array<string, mixed> $record
     */
    public function put(array $record): void
    {
        unset($record['position']);
        $this->cache->put($this->key((string) $record['id']), $record, $this->ttl);
    }

    /**
     * Queued and running job ids, oldest first. Ids whose record expired or
     * finished are dropped.
     *
     * @return array<int, string>
     */
    public function queue(): array
    {
        $ids = $this->cache->get(self::PREFIX . 'queue', []);

        return array_values(array_filter(is_array($ids) ? $ids : [], function ($id) {
            $record = $this->get((string) $id);

            return $record !== null && !in_array($record['status'] ?? null, self::FINISHED, true);
        }));
    }

    public function position(string $id): ?int
    {
        $index = array_search($id, $this->queue(), true);

        return $index === false ? null : $index;
    }

    public function enqueue(string $id): int
    {
        return $this->mutateQueue(function (array $ids) use ($id) {
            if (!in_array($id, $ids, true)) {
                $ids[] = $id;
            }

            return $ids;
        }, $id);
    }

    public function dequeue(string $id): void
    {
        $this->mutateQueue(fn (array $ids) => array_values(array_filter($ids, fn ($queued) => $queued !== $id)), $id);
    }

    /**
     * An unfinished job for the same company and extension, so a double click
     * or a repeated CLI call returns the job already queued.
     *
     * @return array<string, mixed>|null
     */
    public function activeFor(string $company, string $extensionUuid): ?array
    {
        foreach ($this->queue() as $id) {
            $record = $this->get($id);
            if ($record && ($record['company'] ?? null) === $company && ($record['extension']['uuid'] ?? null) === $extensionUuid) {
                return $this->find($id);
            }
        }

        return null;
    }

    /**
     * @param callable(array<int, string>): array<int, string> $mutate
     */
    private function mutateQueue(callable $mutate, string $id): int
    {
        $apply = function () use ($mutate, $id) {
            $ids = $mutate($this->queue());
            $this->cache->put(self::PREFIX . 'queue', $ids, $this->ttl);
            $index = array_search($id, $ids, true);

            return $index === false ? -1 : $index;
        };

        $store = method_exists($this->cache, 'getStore') ? $this->cache->getStore() : null;
        if ($store instanceof \Illuminate\Contracts\Cache\LockProvider) {
            return $store->lock(self::PREFIX . 'queue-mutex', 10)->block(5, $apply);
        }

        return $apply();
    }

    private function key(string $id): string
    {
        return self::PREFIX . 'jobs:' . $id;
    }

    private static function newId(): string
    {
        $bytes    = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
