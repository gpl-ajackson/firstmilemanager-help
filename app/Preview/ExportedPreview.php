<?php

namespace App\Preview;

/** What a Help Preview export wrote. */
final readonly class ExportedPreview
{
    public function __construct(
        /** Pages written, the page not found and the list of drafts included. */
        public int $pages,
        /** Draft Articles shown, each marked as a draft. */
        public int $drafts,
        /** Files written in all: pages, styles, fonts, Pictures and Pages' own files. */
        public int $files,
    ) {}
}
