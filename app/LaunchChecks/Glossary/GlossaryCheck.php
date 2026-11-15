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
 * GLOSSARY.md without blocking work. It reads Stockist's GLOSSARY.md too (--stockist, fmm#56):
 * a term both apps define is a shared term, and help must mean by it what Stockist means, since
 * a Joint Operator's people meet it in both. Each shared term's Term Status records Stockist's
 * definition as last checked (stockist_fingerprint); one with none fails, and a definition
 * Stockist has changed since is reported. The Words check fails Stockist's avoid words for them. Only a published entry naming a term GLOSSARY.md no
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

        $stockist = $context->stockistGlossary();
        if ($context->stockistPath === null) {
            $findings->notRun('Glossary, the terms First Mile Manager shares with Stockist: give --stockist=<a checkout of gpl-ajackson/stockist>');
        } elseif ($stockist === null) {
            $findings->fail('glossary.stockist-source', MainGlossary::FILE, null, "Stockist's checkout at {$context->stockistPath} has no GLOSSARY.md, so the shared terms can't be checked: is --stockist a checkout of gpl-ajackson/stockist?");
        }

        $entriesByTerm = $this->checkEntries($context->glossaryEntries(), $glossary, $statuses, $findings, $stockist);
        $this->checkStatuses($context, $glossary, $statuses, $entriesByTerm, $findings);
        if ($stockist !== null) {
            $this->checkSharedTerms($glossary, $stockist, $statuses, $findings);
        }
    }

    /**
     * The terms First Mile Manager's GLOSSARY.md and Stockist's both define, by First Mile
     * Manager's name, each with Stockist's definition.
     *
     * @return array<string, GlossaryTerm>
     */
    public static function sharedTerms(MainGlossary $glossary, MainGlossary $stockist): array
    {
        $shared = [];
        foreach (array_keys($glossary->terms) as $name) {
            $spelled = $stockist->spelledAs($name);
            if ($spelled !== null) {
                $shared[$name] = $stockist->terms[$spelled];
            }
        }

        return $shared;
    }

    /**
     * Fails a shared term whose Term Status doesn't record that its help was checked against
     * Stockist's definition, reports one whose Stockist definition has changed since, and
     * reports a stockist_fingerprint on a term Stockist doesn't define.
     */
    private function checkSharedTerms(MainGlossary $glossary, MainGlossary $stockist, TermStatusList $statuses, Findings $findings): void
    {
        $shared = self::sharedTerms($glossary, $stockist);
        foreach ($statuses->entries as $name => $status) {
            $theirs = $shared[$name] ?? null;
            if ($theirs === null) {
                if ($status->stockistFingerprint !== null) {
                    $findings->report('glossary.shared-term', TermStatusList::FILE, $status->line, "\"{$name}\" is not a term in Stockist's GLOSSARY.md any more: take its stockist_fingerprint off");
                }

                continue;
            }
            if ($status->stockistFingerprint === null) {
                $findings->fail('glossary.shared-term', TermStatusList::FILE, $status->line, "\"{$name}\" is a term in Stockist's GLOSSARY.md too: check that its Glossary Entry and the Articles that use it mean what Stockist means by it (\"{$theirs->definition}\"), then set stockist_fingerprint: '{$theirs->fingerprint()}'");
            } elseif ($status->stockistFingerprint !== $theirs->fingerprint()) {
                $findings->report('glossary.shared-meaning', TermStatusList::FILE, $status->line, "Stockist's GLOSSARY.md has changed its definition of \"{$name}\" since it was last checked: check that its Glossary Entry and the Articles that use it still mean what Stockist means by it, then set stockist_fingerprint: '{$theirs->fingerprint()}'");
            }
        }
    }

    /**
     * Fails each entry whose term isn't exactly a GLOSSARY.md term, and reports one whose term
     * isn't Published.
     *
     * @param  list<ArticleFile>  $entries
     * @return array<string, true> the terms that have an entry
     */
    private function checkEntries(array $entries, MainGlossary $glossary, TermStatusList $statuses, Findings $findings, ?MainGlossary $stockist): array
    {
        $byTerm = [];
        foreach ($entries as $entry) {
            $term = $entry->text('term');
            if ($term === null || $term === '') {
                continue; // check 1 names a missing term, as the rebuild refuses it
            }
            $line = $entry->lineOf('term');
            if (! $glossary->has($term) && $stockist?->has($term) === true) {
                $findings->fail('glossary.term', $entry->file, $line, "\"{$term}\" is a term in Stockist's GLOSSARY.md, not First Mile Manager's: this help centre's Glossary explains First Mile Manager's terms, so explain it in a Joint-only Article instead");

                continue;
            }
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
