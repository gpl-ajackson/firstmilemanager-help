<?php

namespace App\LaunchChecks\ArticleHeader;

use App\Content\ArticleSource;
use App\Content\ContentProblem;
use App\LaunchChecks\ArticleFile;
use App\LaunchChecks\Check;
use App\LaunchChecks\CheckContext;
use App\LaunchChecks\Findings;

/**
 * Launch Check 1, the header half: every header field present and valid (the content
 * library's own rules, so a rebuild and this check never disagree), then each field's rule
 * that needs the main project or the content folder's lists (rules()).
 */
final readonly class ArticleHeaderCheck implements Check
{
    public function run(CheckContext $context, Findings $findings): void
    {
        $articles = [];
        foreach ($context->articles() as $article) {
            $articles[$article->file] = $article;
        }

        foreach ($context->contentProblems() as $problem) {
            // A Picture an Article can't show is Launch Check 12's (PicturesCheck), at its line.
            if ($problem->field === 'Picture') {
                continue;
            }
            $article = $articles[$problem->file] ?? null;
            $rule = 'header.'.str_replace(' ', '_', strtolower($problem->field));
            $findings->fail($rule, $problem->file, $article === null ? null : $this->lineOf($article, $problem), $problem->message);
        }

        $areas = ProductAreaList::read($context->contentPath, $findings);
        $areas->checkAgainst($context->mainProject, $findings);
        $owners = ArticleOwnerList::read($context->contentPath, $findings);
        $owners->check($findings, $context->github);

        $rules = $this->rules($context, $areas, $owners);
        foreach ($articles as $article) {
            foreach ($rules as $rule) {
                // A field whose value the rebuild refuses is named once, by the rebuild's rule. A rule
                // for a field the content library doesn't know yet still runs.
                $known = in_array($rule->field(), ArticleSource::FIELDS, true);
                if (! $known || ! $context->refuses($article->file, $rule->field())) {
                    $rule->check($article, $findings);
                }
            }
        }
    }

    /**
     * One rule per header data field that needs more than the file itself. Add a field's rule
     * here when it needs the main project or a list in the content folder.
     *
     * @return list<HeaderRule>
     */
    private function rules(CheckContext $context, ProductAreaList $areas, ArticleOwnerList $owners): array
    {
        return [
            new SummaryRule,
            new OwnerRule($owners),
            new ProductAreaRule($areas),
            new TeamRolesRule($context->mainProject),
        ];
    }

    private function lineOf(ArticleFile $article, ContentProblem $problem): int
    {
        return match ($problem->field) {
            'body' => $article->bodyStartLine,
            default => $article->lineOf($problem->field),
        };
    }
}
