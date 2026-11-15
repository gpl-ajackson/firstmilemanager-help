<?php

namespace App\LaunchChecks\ArticleHeader;

use App\LaunchChecks\ArticleFile;
use App\LaunchChecks\Findings;

/** product_area: one of the app's menus, from the Product Area list. */
final readonly class ProductAreaRule implements HeaderRule
{
    public function __construct(private ProductAreaList $areas) {}

    public function field(): string
    {
        return 'product_area';
    }

    public function check(ArticleFile $article, Findings $findings): void
    {
        $area = $article->text('product_area');
        if ($area === null || $this->areas->has($area)) {
            return;
        }

        $findings->fail('header.product_area', $article->file, $article->lineOf('product_area'), $this->areas->exists
            ? "\"{$area}\" is not on the Product Area list (".ProductAreaList::FILE.'): '.implode(', ', $this->areas->names())
            : "\"{$area}\" can't be checked: the content folder has no ".ProductAreaList::FILE." (the app's menus, name for name)");
    }
}
