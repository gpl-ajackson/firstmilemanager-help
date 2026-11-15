<?php

namespace App\LaunchChecks\Glossary;

use App\Content\GlossaryEntrySource;
use App\LaunchChecks\ArticleFile;
use App\LaunchChecks\ArticleHeader\ProductAreaList;
use App\LaunchChecks\Check;
use App\LaunchChecks\CheckContext;
use App\LaunchChecks\Findings;
use App\LaunchChecks\WordPattern;

/**
 * Launch Check 4, Glossary (spec #30): the published Glossary keeps up with the main project's
 * GLOSSARY.md without blocking work. Only a published entry naming a term GLOSSARY.md no
 * longer has (or writes differently) fails. Reported, never failing: terms with no Term Status
 * (failing at launch), Not built yet terms Stockist's screens now show, Ahead terms, changed
 * definitions, and Term Statuses that don't match the entries. It runs with every Launch Check
 * run and weekly on its own (help:glossary-check), which posts its reports to the Content Owner.
 */
final readonly class GlossaryCheck implements Check
{
    public function run(CheckContext $context, Findings $findings): void
    {
        $glossary = $context->mainProject->glossary();
        if ($glossary === null) {
            $findings->fail('glossary.source', MainGlossary::FILE, null, 'the main project has no GLOSSARY.md, so no term can be checked: is --main-project a checkout of gpl-ajackson/firstmilemanager?');

            return;
        }

        $areas = ProductAreaList::read($context->contentPath, new Findings);
        $statuses = TermStatusList::read($context->contentPath, $glossary, $areas, $findings);

        $entriesByTerm = $this->checkEntries($context->glossaryEntries(), $glossary, $statuses, $findings);
        $this->checkStatuses($context, $glossary, $statuses, $entriesByTerm, $findings);
    }

    /**
     * Fails each entry whose term isn't exactly a GLOSSARY.md term, and reports one whose term
     * isn't Published.
     *
     * @param  list<ArticleFile>  $entries
     * @return array<string, true> the terms that have an entry
     */
    private function checkEntries(array $entries, MainGlossary $glossary, TermStatusList $statuses, Findings $findings): array
    {
        $byTerm = [];
        foreach ($entries as $entry) {
            $term = $entry->text('term');
            if ($term === null || $term === '') {
                continue; // check 1 names a missing term, as the rebuild refuses it
            }
            $line = $entry->lineOf('term');
            if (! $glossary->has($term)) {
                $spelled = $glossary->spelledAs($term);
                $findings->fail('glossary.term', $entry->file, $line, $spelled === null
                    ? "\"{$term}\" is not a term in GLOSSARY.md: an entry names a term exactly as GLOSSARY.md does, so rename the entry with the term's new name (keeping the old one in former_names) or remove it"
                    : "\"{$term}\" is not a term in GLOSSARY.md, which writes it \"{$spelled}\"");

                continue;
            }
            $byTerm[$term] = true;
            $status = $statuses->entries[$term] ?? null;
            if ($status !== null && $status->status !== TermStatus::Published) {
                $findings->report('glossary.entries', $entry->file, $line, "\"{$term}\" has a Glossary Entry, but its Term Status is {$status->status->value}: mark it Published, or remove the entry");
            }
        }

        return $byTerm;
    }

    /** @param  array<string, true>  $entriesByTerm */
    private function checkStatuses(CheckContext $context, MainGlossary $glossary, TermStatusList $statuses, array $entriesByTerm, Findings $findings): void
    {
        foreach ($glossary->terms as $name => $term) {
            if (! isset($statuses->named[$name])) {
                $message = "\"{$name}\" has no Term Status: add it with its status (Published, Internal or Not built yet), its home product_area and fingerprint: {$term->fingerprint()}";
                $context->atLaunch
                    ? $findings->fail('glossary.term-status', TermStatusList::FILE, null, $message)
                    : $findings->report('glossary.term-status', TermStatusList::FILE, null, $message.' (this fails at launch)');
            }
        }

        foreach ($statuses->named as $name => $line) {
            if (! $glossary->has($name)) {
                $findings->report('glossary.term-status', TermStatusList::FILE, $line, "\"{$name}\" is not a term in GLOSSARY.md: take it off the list, or rename it as GLOSSARY.md now names it");
            }
        }

        $screenText = null;
        foreach ($statuses->entries as $name => $status) {
            $term = $glossary->terms[$name] ?? null;
            if ($term === null) {
                continue;
            }
            if ($status->status === TermStatus::Published && ! isset($entriesByTerm[$name])) {
                $file = GlossaryEntrySource::addressNameOf($name).'.md';
                $findings->report('glossary.entries', TermStatusList::FILE, $status->line, "\"{$name}\" is Published but has no Glossary Entry (glossary/{$file} or client-glossary/{$file})");
            }
            if ($status->status === TermStatus::NotBuiltYet) {
                $screenText ??= $context->mainProject->screenText();
                $shownIn = $this->shownIn($name, $screenText);
                if ($shownIn !== null) {
                    $findings->report('glossary.not-built-yet', TermStatusList::FILE, $status->line, "\"{$name}\" is Not built yet, but the main project's screens now show it ({$shownIn}): if its feature has shipped, write its Glossary Entry and mark it Published");
                }
            }
            if ($status->ahead) {
                $findings->report('glossary.ahead', TermStatusList::FILE, $status->line, "\"{$name}\" is marked Ahead: its entries say less than GLOSSARY.md, so once the rest of its definition ships, update them and take the mark off");
            }
            if ($status->fingerprint !== $term->fingerprint()) {
                $findings->report('glossary.definition', TermStatusList::FILE, $status->line, "GLOSSARY.md's definition of \"{$name}\" has changed since it was last checked: check its entries against it, then set fingerprint: {$term->fingerprint()}");
            }
        }
    }

    /**
     * The first language file whose text names the term (whole words, any capitals, plural too).
     *
     * @param  array<string, list<string>>  $screenText
     */
    private function shownIn(string $term, array $screenText): ?string
    {
        $pattern = WordPattern::of($term);
        foreach ($screenText as $file => $strings) {
            foreach ($strings as $text) {
                if (preg_match($pattern, $text) === 1) {
                    return $file;
                }
            }
        }

        return null;
    }
}
