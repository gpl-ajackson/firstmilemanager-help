<?php

namespace App\LaunchChecks\PersonalDetails;

use App\LaunchChecks\Check;
use App\LaunchChecks\CheckContext;
use App\LaunchChecks\Findings;
use Illuminate\Support\Facades\Config;
use Symfony\Component\Finder\Finder;

/**
 * Launch Check 6, Personal details (spec #30): no email address, phone number or postcode in
 * the content outside the Trentside demo data and Stockist's own contact details, so no real
 * person's details get into help. It reads every text file in the content folder (Articles of
 * every status, Glossary Entries, Release Notes, Change Summaries and the lists) and every
 * Picture Recipe, whose alt text is published and whose steps sign in as Trentside's people.
 */
final readonly class PersonalDetailsCheck implements Check
{
    /** The content's text files. */
    private const TEXT_FILES = ['*.md', '*.yaml', '*.yml'];

    public function run(CheckContext $context, Findings $findings): void
    {
        $allowed = [
            ...TrentsideDemoData::keysIn($context->mainProject),
            ...$this->stockistsOwn(),
        ];

        foreach ($this->files($context) as $name => $path) {
            foreach (PersonalDetails::in((string) file_get_contents($path)) as $detail) {
                if (! isset($allowed[$detail->key()])) {
                    $findings->fail("personal-details.{$detail->kind}", $name, $detail->line,
                        "\"{$detail->text}\" is {$detail->name()} that isn't in the Trentside demo data or Stockist's own contact details: use a Trentside one, never a real person's");
                }
            }
        }
    }

    /**
     * Stockist's own contact details: the address "Still stuck?" sends Staff to, which is
     * the marketing site's contact address.
     *
     * @return array<string, true>
     */
    private function stockistsOwn(): array
    {
        $own = [];
        foreach (PersonalDetails::in(Config::string('help.still_stuck_email')) as $detail) {
            $own[$detail->key()] = true;
        }

        return $own;
    }

    /**
     * Every text file in the content folder (named relative to it, as the rebuild names them)
     * and every Recipe in the pictures folder (named pictures/<file>).
     *
     * @return array<string, string> Name in findings => path.
     */
    private function files(CheckContext $context): array
    {
        $files = [];
        if (is_dir($context->contentPath)) {
            foreach (Finder::create()->files()->name(self::TEXT_FILES)->in($context->contentPath)->sortByName() as $file) {
                $files[$file->getRelativePathname()] = $file->getPathname();
            }
        }
        $pictures = rtrim($context->picturesPath ?? Config::string('help.pictures_path'), '/');
        if (is_dir($pictures)) {
            foreach (Finder::create()->files()->name(['*.yaml', '*.yml'])->in($pictures)->depth(0)->sortByName() as $file) {
                $files['pictures/'.$file->getFilename()] = $file->getPathname();
            }
        }

        return $files;
    }
}
