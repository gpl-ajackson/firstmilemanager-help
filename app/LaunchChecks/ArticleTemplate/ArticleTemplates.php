<?php

namespace App\LaunchChecks\ArticleTemplate;

use App\Content\ArticleType;
use App\LaunchChecks\CheckContext;
use App\LaunchChecks\Findings;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The Article Templates in docs/writing/templates/, as check 1 reads them. Each template's
 * header data names its Article Type, lists its parts in page order and may give the guide
 * length the length report compares against:
 *
 *     ---
 *     template: How-to
 *     guide_words: 400
 *     parts:
 *       - part: In short
 *       - part: Decide first
 *         when_needed: true
 *       - part: Related articles
 *         field: related_articles
 *     ---
 *
 * A part is required unless it says `when_needed: true`. A part with `field` is built by the
 * page from that header data field; any other part is a "## <part>" heading in the body. Files
 * for other templates (the Glossary Entry's) are left to their own checks.
 */
final class ArticleTemplates
{
    /**
     * @param  array<string, ArticleTemplate>  $templates  Article Type => its template
     */
    private function __construct(public readonly array $templates) {}

    public static function read(string $path, Findings $findings): self
    {
        if (! is_dir($path)) {
            return new self([]);
        }

        $templates = [];
        foreach (Finder::create()->files()->name('*.md')->in($path)->depth(0)->sortByName() as $file) {
            $template = self::readOne($file->getPathname(), CheckContext::displayPath($file->getPathname()), $findings);
            if ($template !== null) {
                $templates[$template->type] = $template;
            }
        }

        return new self($templates);
    }

    public function for(string $type): ?ArticleTemplate
    {
        return $this->templates[$type] ?? null;
    }

    private static function readOne(string $path, string $file, Findings $findings): ?ArticleTemplate
    {
        $problem = fn (string $message) => $findings->fail('template.format', $file, 1, $message);

        if (preg_match('/\A---\R(.*?)\R---(?:\R|\z)/s', (string) file_get_contents($path), $parts) !== 1) {
            $problem('a template starts with header data between two "---" lines naming its template and parts');

            return null;
        }
        try {
            $header = Yaml::parse($parts[1]);
        } catch (ParseException $e) {
            $problem('its header data is not valid YAML: '.$e->getMessage());

            return null;
        }

        $type = is_array($header) ? ($header['template'] ?? null) : null;
        if (! is_string($type) || ArticleType::tryFrom($type) === null) {
            return null; // Not an Article Template, such as the Glossary Entry template.
        }

        $guideWords = $header['guide_words'] ?? null;
        if ($guideWords !== null && (! is_int($guideWords) || $guideWords < 1)) {
            $problem('"guide_words" must be a whole number of words');
            $guideWords = null;
        }

        $templateParts = [];
        foreach (is_array($header['parts'] ?? null) ? $header['parts'] : [] as $part) {
            $name = is_array($part) ? ($part['part'] ?? null) : null;
            $field = is_array($part) ? ($part['field'] ?? null) : null;
            $whenNeeded = is_array($part) ? ($part['when_needed'] ?? false) : false;
            if (! is_string($name) || $name === '' || ($field !== null && ! is_string($field)) || ! is_bool($whenNeeded)) {
                $problem('each part needs "part" (its heading), and may give "field" (header data it comes from) and "when_needed: true"');

                continue;
            }
            $templateParts[] = new TemplatePart($name, ! $whenNeeded, $field);
        }
        if ($templateParts === []) {
            $problem("the {$type} template lists no parts");
        }

        return new ArticleTemplate($type, $file, $templateParts, $guideWords);
    }
}
