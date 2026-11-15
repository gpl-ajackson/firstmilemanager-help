<?php

namespace App\Search;

use App\Content\Article;
use App\Content\ArticleSource;
use App\Content\ArticleType;
use App\Content\AudienceTier;
use Dom\Element;
use Dom\HTMLDocument;
use UnexpectedValueException;

/**
 * @internal How a Published Article is indexed and how a hit turns back into a Result.
 *
 * Ranking (spec #30): title, then summary and Section headings, then body. Meilisearch ranks
 * by attribute order, so the summary and every Section heading share one attribute, "lead"
 * (summary first, then the headings in page order), with "lead_sections" beside it holding
 * each heading's Section Name, or null. A match's index in "lead" says which heading matched.
 */
final class ArticleDocument
{
    /** What result() reads of a hit. */
    public const RESULT_FIELDS = ['name', 'title', 'summary', 'type', 'product_area', 'audience_tier', 'lead', 'lead_sections'];

    /** The headings the page itself draws for every Article of a type; never a Section to open. */
    private const PAGE_PART_HEADINGS = '.in-short, .what-you-see';

    /**
     * @return array<string, mixed>
     */
    public static function of(Article $article): array
    {
        $document = HTMLDocument::createFromString('<!DOCTYPE html><body>'.$article->body_html, LIBXML_NOERROR);
        $body = $document->body;
        assert($body !== null);

        $lead = [$article->summary];
        $sections = [null];
        foreach (iterator_to_array($body->querySelectorAll('h1, h2, h3, h4, h5, h6')) as $heading) {
            if ($heading->closest(self::PAGE_PART_HEADINGS) === null) {
                $lead[] = self::words($heading);
                $id = $heading->getAttribute('id');
                $sections[] = $id !== null && $id !== '' ? $id : null;
            }
            $heading->remove();
        }

        return [
            'id' => 'article-'.$article->name,
            'kind' => 'article',
            'name' => $article->name,
            'title' => $article->title,
            'summary' => $article->summary,
            'type' => $article->type->value,
            'product_area' => $article->product_area,
            'audience_tier' => $article->audience_tier->value,
            'tier_rank' => $article->audience_tier === AudienceTier::Client ? 1 : 0,
            'lead' => $lead,
            'lead_sections' => $sections,
            'body' => self::words($body),
            // Each Troubleshooting message as a pattern with its Placeholders open (MessagePattern).
            'message_patterns' => array_values(array_filter(array_map(MessagePattern::of(...), $article->messages))),
            'has_messages' => $article->messages !== [],
        ];
    }

    /**
     * A hit as a reader's result. It opens at a Section when that Section's heading matched
     * more of the words searched for than the title did.
     *
     * @param  array<string, mixed>  $hit
     */
    public static function result(array $hit, int $place): Result
    {
        /** @var list<string> $lead */
        $lead = $hit['lead'];
        /** @var list<string|null> $sections */
        $sections = $hit['lead_sections'];
        /** @var array<string, list<array{start: int, length: int, indices?: list<int>}>> $matches */
        $matches = $hit['_matchesPosition'] ?? [];

        $titleWords = self::matchedWords(self::text($hit, 'title'), $matches['title'] ?? []);
        $best = null;
        $bestWords = count($titleWords);
        foreach ($sections as $index => $section) {
            if ($section === null) {
                continue;
            }
            $inHeading = array_filter($matches['lead'] ?? [], fn (array $match): bool => ($match['indices'][0] ?? null) === $index);
            $words = count(self::matchedWords($lead[$index], $inHeading));
            if ($words > $bestWords) {
                [$best, $bestWords] = [$index, $words];
            }
        }

        $name = self::text($hit, 'name');
        $address = ArticleSource::addressOf($name).($best !== null ? '#'.$sections[$best] : '');

        return new Result(
            place: $place,
            articleName: $name,
            title: self::text($hit, 'title'),
            summary: self::text($hit, 'summary'),
            type: ArticleType::from(self::text($hit, 'type')),
            productArea: self::text($hit, 'product_area'),
            audienceTier: AudienceTier::from(self::text($hit, 'audience_tier')),
            address: $address,
            articleAddress: $address,
            sectionHeading: $best !== null ? $lead[$best] : null,
        );
    }

    /**
     * The distinct words matched in $text (Meilisearch gives byte offsets).
     *
     * @param  array<array{start: int, length: int}>  $matches
     * @return list<string>
     */
    private static function matchedWords(string $text, array $matches): array
    {
        $words = array_map(fn (array $match): string => mb_strtolower(substr($text, $match['start'], $match['length'])), $matches);

        return array_values(array_unique($words));
    }

    /**
     * A text field of a hit, which this class indexed itself.
     *
     * @param  array<string, mixed>  $hit
     */
    private static function text(array $hit, string $field): string
    {
        $value = $hit[$field] ?? null;
        if (! is_string($value)) {
            throw new UnexpectedValueException("The search index has no text \"{$field}\" for a hit; rebuild it with help:rebuild.");
        }

        return $value;
    }

    private static function words(Element $element): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $element->textContent));
    }
}
