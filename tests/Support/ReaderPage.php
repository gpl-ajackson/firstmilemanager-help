<?php

namespace Tests\Support;

use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Response;

/**
 * A page as a reader reads it: the words in a part of the page, found by a CSS selector, with
 * white space as a browser shows it. For tests that ask "what does this part of the page say?"
 * without matching the page's markup character for character.
 *
 *     ReaderPage::of($this->get('/articles/receive-at-the-dock'))->text('.details')
 */
final readonly class ReaderPage
{
    private function __construct(private HTMLDocument $document) {}

    /**
     * @param  TestResponse<Response>  $response
     * @param  int  $status  The status the page must have (200 unless said otherwise).
     */
    public static function of(TestResponse $response, int $status = 200): self
    {
        $response->assertStatus($status);

        return new self(HTMLDocument::createFromString((string) $response->getContent(), LIBXML_NOERROR));
    }

    /** A page from its HTML, such as a browser's view of it after its script ran (ReaderBrowser). */
    public static function ofHtml(string $html): self
    {
        return new self(HTMLDocument::createFromString($html, LIBXML_NOERROR));
    }

    /** The words of the first element matching $selector; fails the test when there is none. */
    public function text(string $selector): string
    {
        $element = $this->document->querySelector($selector);
        Assert::assertNotNull($element, "The page has no {$selector}");

        return self::words($element);
    }

    /**
     * The words of every element matching $selector, in page order.
     *
     * @return list<string>
     */
    public function texts(string $selector): array
    {
        return array_values(array_map(self::words(...), iterator_to_array($this->document->querySelectorAll($selector))));
    }

    /**
     * The value a form field matching $selector holds, as the browser would send it (an input's
     * value, a text area's text), or null when there is no such field.
     */
    public function value(string $selector): ?string
    {
        $field = $this->document->querySelector($selector);
        if ($field === null) {
            return null;
        }

        return strtolower($field->tagName) === 'textarea' ? (string) $field->textContent : (string) $field->getAttribute('value');
    }

    public function has(string $selector): bool
    {
        return $this->document->querySelector($selector) !== null;
    }

    /**
     * Each link matching $selector as [its words, its address], in page order.
     *
     * @return list<array{string, string}>
     */
    public function links(string $selector): array
    {
        $links = [];
        foreach ($this->document->querySelectorAll($selector) as $link) {
            $links[] = [self::words($link), (string) $link->getAttribute('href')];
        }

        return $links;
    }

    /**
     * The attributes of every element matching $selector, in page order, for what a reader's
     * browser reads rather than shows (an image's alt text and sources).
     *
     * @return list<array<string, string>>
     */
    public function attributes(string $selector): array
    {
        $found = [];
        foreach ($this->document->querySelectorAll($selector) as $element) {
            $attributes = [];
            foreach ($element->attributes as $attribute) {
                $attributes[$attribute->name] = (string) $attribute->value;
            }
            $found[] = $attributes;
        }

        return $found;
    }

    /**
     * Each candidate in an image's srcset, as [width => address], in the order written.
     *
     * @return array<int, string>
     */
    public static function srcset(string $srcset): array
    {
        $sources = [];
        foreach (explode(',', $srcset) as $candidate) {
            [$address, $width] = array_pad(preg_split('/\s+/', trim($candidate)) ?: [], 2, '');
            $sources[(int) rtrim($width, 'w')] = $address;
        }

        return $sources;
    }

    private static function words(Element $element): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $element->textContent));
    }
}
