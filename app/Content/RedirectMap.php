<?php

namespace App\Content;

/**
 * Every address the content folder uses and every old address it redirects for good (ADR-0001),
 * gathered during a rebuild and checked as a whole: an address in use never redirects, no
 * old address is claimed twice, and a chain of redirects is followed to its end, so a reader
 * is sent straight to where the help is now.
 *
 * Any kind of content adds to it: Articles (their own addresses, former addresses, Retired
 * Articles' replacements) now; renamed Glossary Entries and Product Areas later.
 */
final class RedirectMap
{
    /** @var array<string, array{file: string, served: bool}> */
    private array $addresses = [];

    /** @var array<string, array{to: string, file: string, field: string}> */
    private array $redirects = [];

    /** @var list<ContentProblem> */
    private array $problems = [];

    /**
     * An address a file uses. One that is not served (a Draft's) is still kept from any other
     * use, but nothing is redirected to it until it is.
     */
    public function uses(string $address, string $file, bool $served = true): void
    {
        $this->addresses[$address] = ['file' => $file, 'served' => $served];
    }

    /** An old address that sends readers to $to for good, written in $file's $field. */
    public function redirect(string $from, string $to, string $file, string $field): void
    {
        if (isset($this->redirects[$from])) {
            $this->problems[] = new ContentProblem($file, $field, "\"{$from}\" already redirects to {$this->redirects[$from]['to']} ({$this->redirects[$from]['file']})");

            return;
        }

        $this->redirects[$from] = ['to' => $to, 'file' => $file, 'field' => $field];
    }

    /** @return list<ContentProblem> */
    public function problems(): array
    {
        $problems = $this->problems;
        foreach ($this->redirects as $from => $redirect) {
            if (isset($this->addresses[$from])) {
                $problems[] = new ContentProblem($redirect['file'], $redirect['field'], "\"{$from}\" is in use by {$this->addresses[$from]['file']}, and an address in use never redirects");
            } elseif ($this->destination($from) === null) {
                $problems[] = new ContentProblem($redirect['file'], $redirect['field'], "\"{$from}\" redirects round in a loop");
            }
        }

        return $problems;
    }

    /**
     * The redirects to keep, each going straight to its final address. Call once problems()
     * is empty. A redirect that ends at an address not served yet is left out until it is.
     *
     * @return list<array{from_path: string, to_path: string, source_path: string}>
     */
    public function rows(): array
    {
        $rows = [];
        foreach ($this->redirects as $from => $redirect) {
            $to = $this->destination($from);
            if ($to !== null && ($this->addresses[$to]['served'] ?? true)) {
                $rows[] = ['from_path' => $from, 'to_path' => $to, 'source_path' => $redirect['file']];
            }
        }

        return $rows;
    }

    /** Where a redirect finally leads, or null when it loops. */
    private function destination(string $from): ?string
    {
        $seen = [$from => true];
        $to = $this->redirects[$from]['to'];
        while (isset($this->redirects[$to]) && ! isset($this->addresses[$to])) {
            if (isset($seen[$to])) {
                return null;
            }
            $seen[$to] = true;
            $to = $this->redirects[$to]['to'];
        }

        return $to;
    }
}
