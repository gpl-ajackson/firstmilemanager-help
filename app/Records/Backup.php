<?php

namespace App\Records;

/** One backup of the records, as made or restored by RecordsBackup: its file in the store and its rows, table by table. */
final readonly class Backup
{
    /** @param  array<string, int>  $rows  table => rows */
    public function __construct(
        public string $file,
        public array $rows,
    ) {}

    public function totalRows(): int
    {
        return array_sum($this->rows);
    }
}
