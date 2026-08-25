<?php
declare(strict_types=1);

/**
 * StoreInterface — common contract for JsonStore and SqliteStore.
 *
 * Both storage backends implement this interface, so any service class
 * can type-hint against StoreInterface and accept either implementation.
 *
 * Usage in service constructors:
 *   public function __construct(StoreInterface $store, ...)
 */
interface StoreInterface
{
    /** Load all records from a collection. */
    public function load(string $file): array;

    /** Save (replace) entire collection. */
    public function save(string $file, array $data): void;

    /** Append a record to a collection (no auto-ID). */
    public function append(string $file, array $record): array;

    /** Find first record matching key=value. */
    public function findOne(string $file, string $key, $value): ?array;

    /** Find all records matching key=value. */
    public function findAll(string $file, string $key, $value): array;

    /** Update first record matching key=value. */
    public function updateOne(string $file, string $key, $value, array $updates): bool;

    /** Generate next auto-increment ID for a collection. */
    public function nextId(string $file): int;

    /** Atomic append with auto-assigned ID. */
    public function appendWithId(string $file, array $record): array;

    /** Return filesystem path for the file/database. */
    public function path(string $file): string;

    /**
     * Locked read-modify-write cycle.
     * Callable receives array $records, must return ['records'=>..., 'result'=>...].
     */
    public function withLock(string $file, callable $fn);
}
