<?php

namespace App\Releases;

use App\Content\ChangeSummarySource;
use Carbon\CarbonImmutable;

/** A FMM Release's Release Note as assembled from the Next Release folder, before it is written. */
final readonly class DraftReleaseNote
{
    public function __construct(
        public CarbonImmutable $date,
        /** Where it goes, relative to the content folder: release-notes/<yyyy-mm-dd>.md. */
        public string $file,
        /** The Release Note file's text, as `help:release-note` writes it. */
        public string $contents,
        /** @var list<ChangeSummarySource> The Change Summaries it gathers, by Product Area in menu order. */
        public array $changeSummaries,
    ) {}

    /** @return list<string> The Product Areas it covers, in the app's menu order. */
    public function productAreas(): array
    {
        return array_values(array_unique(array_map(fn (ChangeSummarySource $source): string => $source->summary->productArea, $this->changeSummaries)));
    }
}
