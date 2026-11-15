<?php

namespace App\LaunchChecks\Privacy;

use App\LaunchChecks\Check;
use App\LaunchChecks\CheckContext;
use App\LaunchChecks\Findings;
use App\LaunchChecks\Pages\PagesRun;

/**
 * Launch Check 8, Privacy (spec #30, user stories 95 to 97 and 124; the plan's rule 11): no
 * public page sets a cookie or keeps anything on the reader's device (local or session storage,
 * IndexedDB), with script on as readers have it; and with recording on, as once the help centre
 * is public, a test reader's Search with an email address, phone number and postcode is saved
 * only as markers, and their Search, vote and "Still stuck?" leave nothing about them in any
 * record: not their browser, network address, language, the page they came from or the time.
 *
 * @phpstan-import-type Kept from PagesRun
 * @phpstan-import-type Privacy from PagesRun
 */
final readonly class PrivacyCheck implements Check
{
    /** The test reader's Search: an email address, a phone number and a postcode. */
    public const TEST_SEARCH = 'sam.carter@trentside.example 07700 900123 NG7 2RD';

    /** All a Search Record may keep of it. */
    public const SAVED_AS = '[email] [phone] [postcode]';

    public function run(CheckContext $context, Findings $findings): void
    {
        if (! $context->checkPages) {
            $findings->notRun('Privacy (Launch Check 8): it opens every published page and a test reader searches, votes and asks "Still stuck?" in headless Chromium (give --pages)');

            return;
        }

        $run = $context->pages();
        if ($run->problem !== null) {
            $findings->fail('privacy.pages', CheckContext::displayPath($context->contentPath), null, "the pages couldn't be checked: {$run->problem}");

            return;
        }

        foreach ($run->scans as $scan) {
            $this->kept($scan['address'], $scan['kept'], 'opening the page', $findings);
        }
        if ($run->privacy !== null) {
            $this->testReader($run->privacy, $findings);
        }
    }

    /** @param  Kept  $kept */
    private function kept(string $address, array $kept, string $after, Findings $findings): void
    {
        if ($kept['cookies'] !== []) {
            $findings->fail('privacy.cookie', $address, null, "sets a cookie after {$after} (".implode(', ', array_unique($kept['cookies'])).'): a public page sets none');
        }
        $stored = array_filter([
            $kept['local'] > 0 ? "local storage ({$kept['local']} ".($kept['local'] === 1 ? 'item' : 'items').')' : null,
            $kept['session'] > 0 ? "session storage ({$kept['session']} ".($kept['session'] === 1 ? 'item' : 'items').')' : null,
            $kept['indexedDb'] !== [] ? 'IndexedDB ('.implode(', ', $kept['indexedDb']).')' : null,
        ]);
        if ($stored !== []) {
            $findings->fail('privacy.storage', $address, null, 'keeps '.implode(' and ', $stored)." on the reader's device after {$after}: a public page keeps nothing there");
        }
    }

    /** @param  Privacy  $reader */
    private function testReader(array $reader, Findings $findings): void
    {
        $this->kept('/search', $reader['kept'], 'a test reader searched, voted and asked "Still stuck?"', $findings);

        $typed = "the test Search \"{$reader['typed']}\"";
        if ($reader['searches'] === []) {
            $findings->fail('privacy.search', '/search', null, "{$typed} wasn't recorded, so what a Search Record keeps can't be shown");
        }
        foreach ($reader['searches'] as $words) {
            if ($words !== self::SAVED_AS) {
                $findings->fail('privacy.search', '/search', null, "{$typed} was saved as \"{$words}\": a Search Record keeps only ".self::SAVED_AS.' in their place');
            }
        }
        if (in_array('vote', $reader['expected'], true) && $reader['votes'] < 1) {
            $findings->fail('privacy.records', '/feedback', null, "the test reader's No vote wasn't recorded, so what a vote keeps can't be shown");
        }
        if (in_array('still stuck', $reader['expected'], true) && $reader['stillStuck'] < 1) {
            $findings->fail('privacy.records', '/still-stuck', null, "the test reader's \"Still stuck?\" wasn't counted, so what a count keeps can't be shown");
        }
        foreach ($reader['leaks'] as $leak) {
            $findings->fail('privacy.records', 'records', null, "{$leak} after a test reader searched, voted and asked \"Still stuck?\": no record holds anything about the reader");
        }
    }
}
