<?php

namespace App\Reports;

use Carbon\CarbonImmutable;

/** A commit of the main project that changed a file. */
final readonly class FileChange
{
    public function __construct(
        public string $sha,
        public CarbonImmutable $at,
        public string $subject,
    ) {}
}
