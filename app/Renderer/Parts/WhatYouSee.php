<?php

namespace App\Renderer\Parts;

use League\CommonMark\Node\Block\AbstractBlock;

/** A Troubleshooting Article's "What you see": every message it explains, from its header data. */
final class WhatYouSee extends AbstractBlock
{
    /**
     * @param  list<string>  $messages  Word for word, Placeholders in [square brackets].
     */
    public function __construct(public readonly array $messages)
    {
        parent::__construct();
    }
}
