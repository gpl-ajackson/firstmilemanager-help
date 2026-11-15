<?php

namespace App\Site;

use Carbon\CarbonImmutable;

/** One page search engines may index: its address and, where the content says, when it last changed. */
final readonly class IndexedPage
{
    public function __construct(
        public string $address,
        public ?CarbonImmutable $lastChanged = null,
    ) {}
}
