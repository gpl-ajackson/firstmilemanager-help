<?php

namespace App\LaunchChecks\Indexing;

use Dom\HTMLDocument;

/** What the help centre answered for one address, as a search engine reads it. */
final readonly class Visit
{
    public function __construct(
        public string $address,
        public int $status,
        public string $contentType,
        public string $body,
        public bool $robotsHeaderSaysNoindex,
        public ?string $location,
    ) {}

    public function isHtml(): bool
    {
        return str_starts_with($this->contentType, 'text/html');
    }

    /** "noindex" in the X-Robots-Tag header or a robots meta tag. */
    public function saysNoindex(): bool
    {
        if ($this->robotsHeaderSaysNoindex) {
            return true;
        }
        if (! $this->isHtml()) {
            return false;
        }
        foreach ($this->document()->querySelectorAll('meta[name="robots" i]') as $meta) {
            if (str_contains(strtolower((string) $meta->getAttribute('content')), 'noindex')) {
                return true;
            }
        }

        return false;
    }

    /** A page search engines may index: 200, HTML, and no "noindex". */
    public function isIndexable(): bool
    {
        return $this->status === 200 && $this->isHtml() && ! $this->saysNoindex();
    }

    /** Why search engines may not index it, such as 'it answers 410 and says "noindex"'. */
    public function whyNotIndexable(): string
    {
        $reasons = [];
        if ($this->status !== 200) {
            $reasons[] = "it answers {$this->status}";
        } elseif (! $this->isHtml()) {
            $reasons[] = 'it isn\'t a page';
        }
        if ($this->saysNoindex()) {
            $reasons[] = $reasons === [] ? 'it says "noindex"' : 'says "noindex"';
        }

        return implode(' and ', $reasons);
    }

    /** @return list<string> Every link's href on the page, as written. */
    public function links(): array
    {
        if (! $this->isHtml()) {
            return [];
        }
        $links = [];
        foreach ($this->document()->querySelectorAll('a[href]') as $link) {
            $links[] = (string) $link->getAttribute('href');
        }

        return $links;
    }

    private function document(): HTMLDocument
    {
        return HTMLDocument::createFromString($this->body, LIBXML_NOERROR);
    }
}
