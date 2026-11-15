<?php

namespace App\LaunchChecks\ArticleTemplate;

use App\LaunchChecks\ArticleBody;
use App\LaunchChecks\ArticleFile;
use App\LaunchChecks\Check;
use App\LaunchChecks\CheckContext;
use App\LaunchChecks\Findings;

/**
 * Launch Check 1, the template half: each Article holds the parts its type's Article Template
 * requires, in the template's order. Until the foundation ticket (#70) writes the templates,
 * it says it had none to read and fails nothing.
 */
final class ArticleTemplateCheck implements Check
{
    public function run(CheckContext $context, Findings $findings): void
    {
        $templates = ArticleTemplates::read($context->templatesPath, $findings);
        $where = CheckContext::displayPath($context->templatesPath);

        if ($templates->templates === []) {
            $findings->notRun("Article Template parts: there are no Article Templates in {$where} yet");

            return;
        }

        $untemplated = [];
        foreach ($context->articles() as $article) {
            $type = $article->text('type');
            if ($type === null) {
                continue; // The header check names a missing type.
            }
            $template = $templates->for($type);
            if ($template === null) {
                $untemplated[$type] = true;

                continue;
            }
            $this->checkParts($article, $template, $context, $findings);
        }

        foreach (array_keys($untemplated) as $type) {
            $findings->notRun("Article Template parts of {$type} Articles: there is no {$type} template in {$where} yet");
        }
    }

    private function checkParts(ArticleFile $article, ArticleTemplate $template, CheckContext $context, Findings $findings): void
    {
        $headings = ArticleBody::of($article)->headings();
        $lineOf = function (TemplatePart $part) use ($headings): ?int {
            foreach ($headings as $heading) {
                if ($heading['text'] === $part->name) {
                    return $heading['line'];
                }
            }

            return null;
        };

        $latest = null; // The part furthest down the page so far: [part, line].
        foreach ($template->parts as $index => $part) {
            if ($part->field !== null) {
                // A field the rebuild already refuses is named once, by the header check.
                if ($part->required && ! $article->has($part->field) && ! $context->refuses($article->file, $part->field)) {
                    $findings->fail('template.parts', $article->file, $article->lineOf($part->field),
                        "the {$template->type} template needs {$part->describe()}");
                }

                continue;
            }

            $line = $lineOf($part);
            if ($line === null) {
                if ($part->required) {
                    $findings->fail('template.parts', $article->file, $this->whereMissing($template, $index, $lineOf, $article),
                        "the {$template->type} template needs a {$part->describe()} part{$this->beforeNext($template, $index, $lineOf)}");
                }

                continue;
            }

            if ($latest !== null && $line < $latest[1]) {
                $findings->fail('template.order', $article->file, $line,
                    "{$part->describe()} comes before {$latest[0]->describe()}; the {$template->type} template puts it after");

                continue;
            }
            $latest = [$part, $line];
        }
    }

    /** @param  callable(TemplatePart): ?int  $lineOf */
    private function whereMissing(ArticleTemplate $template, int $index, callable $lineOf, ArticleFile $article): int
    {
        foreach (array_slice($template->parts, $index + 1) as $later) {
            if ($later->field === null && ($line = $lineOf($later)) !== null) {
                return $line;
            }
        }

        return $article->lastLine;
    }

    /** @param  callable(TemplatePart): ?int  $lineOf */
    private function beforeNext(ArticleTemplate $template, int $index, callable $lineOf): string
    {
        foreach (array_slice($template->parts, $index + 1) as $later) {
            if ($later->field === null && $lineOf($later) !== null) {
                return ", before {$later->describe()}";
            }
        }

        return ', after the parts above it';
    }
}
