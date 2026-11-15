<?php

namespace App\LaunchChecks\Pages;

/**
 * One page of the help centre a reader can open, as the page checks visit it: its address, the
 * page template it is drawn with (for Launch Check 11, which times each template; null for pages
 * it doesn't time), the content file it comes from, if any, and the status a reader gets.
 */
final readonly class Page
{
    public const HOME = 'home';

    public const GLOSSARY_ENTRY = 'Glossary Entry';

    public const SEARCH_RESULTS = 'search results';

    public const PRODUCT_AREA = 'Product Area';

    public const ROLE_PATH = 'Role Path';

    public const RELEASE_NOTE = 'Release Note';

    public const RETIRED_NOTICE = 'retired notice';

    public const PAGE_NOT_FOUND = 'page not found';

    public function __construct(
        public string $address,
        public ?string $template = null,
        public ?string $file = null,
        public int $status = 200,
    ) {}
}
