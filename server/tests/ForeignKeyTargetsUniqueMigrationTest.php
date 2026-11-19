<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Schema;

/*
 * MySQL 8.4 refuses to alter a table that a foreign key points at through a non-unique key, so
 * this migration makes those columns unique. These tests run it on SQLite. The MySQL 8.4 and 8.0
 * runs are described in the pull request.
 */

function registryFkTargetsDatabase(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', '', ['driver' => 'sqlite']);
    Schema::swap($connection->getSchemaBuilder());

    return $connection;
}

function registryFkTargetsMigration(): object
{
    return require dirname(__DIR__) . '/migrations/2026_11_18_000001_make_registry_foreign_key_targets_unique.php';
}

/**
 * @return array<string, bool> single-column indexes on `$column`, name => unique
 */
function registryFkTargetsIndexes(SQLiteConnection $connection, string $table, string $column = 'uuid'): array
{
    $indexes = [];
    foreach ($connection->getSchemaBuilder()->getIndexes($table) as $index) {
        if ($index['columns'] === [$column]) {
            $indexes[$index['name']] = (bool) $index['unique'];
        }
    }
    ksort($indexes);

    return $indexes;
}

function registryFkTargetsPlainTable(string $table): void
{
    Schema::create($table, function (Blueprint $blueprint) {
        $blueprint->increments('id');
        $blueprint->char('uuid', 36)->nullable()->index();
    });
}

test('foreign key targets migration covers every Registry Bridge column a foreign key points at', function () {
    registryFkTargetsDatabase();

    expect(registryFkTargetsMigration()->targets())->toBe([
        ['registry_extensions', 'uuid'],
        ['registry_extension_bundles', 'uuid'],
        ['registry_users', 'uuid'],
    ]);
});

test('foreign key targets migration swaps the plain index for a unique one and can be reversed', function () {
    $connection = registryFkTargetsDatabase();
    foreach (registryFkTargetsMigration()->targets() as [$table]) {
        registryFkTargetsPlainTable($table);
    }
    [$first] = registryFkTargetsMigration()->targets()[0];
    $connection->table($first)->insert([['uuid' => 'a'], ['uuid' => 'b'], ['uuid' => null], ['uuid' => null]]);

    $migration = registryFkTargetsMigration();
    $migration->up();
    $migration->up();

    foreach ($migration->targets() as [$table]) {
        expect(registryFkTargetsIndexes($connection, $table))->toBe([$table . '_uuid_unique' => true]);
    }
    expect($connection->table($first)->count())->toBe(4)
        ->and($migration->skipped)->toBe([]);

    // Rollback is performed by a newly loaded migration, as in artisan migrate:rollback.
    registryFkTargetsMigration()->down();
    registryFkTargetsMigration()->down();

    foreach ($migration->targets() as [$table]) {
        expect(registryFkTargetsIndexes($connection, $table))->toBe([$table . '_uuid_index' => false]);
    }
});

test('foreign key targets migration leaves columns a unique or primary key already covers', function () {
    $connection             = registryFkTargetsDatabase();
    [[$unique], [$primary]] = registryFkTargetsMigration()->targets();
    Schema::create($unique, function (Blueprint $blueprint) {
        $blueprint->increments('id');
        $blueprint->char('uuid', 36)->index();
        $blueprint->unique(['uuid'], 'existing_uuid_key');
    });
    Schema::create($primary, function (Blueprint $blueprint) {
        $blueprint->char('uuid', 36)->primary();
    });

    $migration = registryFkTargetsMigration();
    $migration->up();
    $migration->down();

    expect(registryFkTargetsIndexes($connection, $unique))->toEqual([$unique . '_uuid_index' => false, 'existing_uuid_key' => true])
        ->and(registryFkTargetsIndexes($connection, $primary))->not->toHaveKey($primary . '_uuid_unique')
        ->and($migration->skipped)->toBe([]);
});

test('foreign key targets migration skips a table with duplicate values and says why', function () {
    $connection               = registryFkTargetsDatabase();
    [[$duplicated], [$clean]] = registryFkTargetsMigration()->targets();
    registryFkTargetsPlainTable($duplicated);
    registryFkTargetsPlainTable($clean);
    $connection->table($duplicated)->insert([['uuid' => 'dup'], ['uuid' => 'dup']]);

    $migration = registryFkTargetsMigration();
    $migration->up();

    expect(registryFkTargetsIndexes($connection, $duplicated))->toBe([$duplicated . '_uuid_index' => false])
        ->and(registryFkTargetsIndexes($connection, $clean))->toBe([$clean . '_uuid_unique' => true])
        ->and($migration->skipped)->toHaveCount(1)
        ->and($migration->skipped[0])->toContain($duplicated . '.uuid')->toContain('duplicate');
});

test('foreign key targets migration ignores tables that do not exist', function () {
    $connection = registryFkTargetsDatabase();

    $migration = registryFkTargetsMigration();
    $migration->up();
    $migration->down();

    expect($connection->getSchemaBuilder()->getTables())->toBe([])
        ->and($migration->skipped)->toBe([]);
});
