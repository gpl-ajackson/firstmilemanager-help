<?php

namespace Tests\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * A small folder shaped like a checkout of the marketing site (gpl-ajackson/firstmilemanager-marketing)
 * at main, for Launch Check 9's facts: only src/data/site.ts, written as the real one is (a
 * TypeScript object with nested objects and comments), holding the facts every fixture content
 * folder starts with (FixtureContent::DEFAULT_FACTS).
 *
 *     marketingSite()->site(['stage' => 'Beta']);
 *     marketingSite()->file('src/data/site.ts', 'export const site = {};');
 */
final class FixtureMarketingSite
{
    /** @var list<string> */
    private static array $made = [];

    private function __construct(private readonly string $path) {}

    public static function make(): self
    {
        $path = sys_get_temp_dir().'/marketing-site-'.getmypid().'-'.Str::lower(Str::random(12));
        File::ensureDirectoryExists($path);
        self::$made[] = $path;

        return (new self($path))->site();
    }

    public static function removeAll(): void
    {
        foreach (self::$made as $path) {
            File::deleteDirectory($path);
        }
        self::$made = [];
    }

    /**
     * Writes src/data/site.ts with the default facts overridden by $facts (name, description,
     * stage, url).
     *
     * @param  array<string, string>  $facts
     */
    public function site(array $facts = []): self
    {
        $facts = array_map(
            fn (string $value): string => "'".addcslashes($value, "'\\")."'",
            [...FixtureContent::DEFAULT_FACTS, ...$facts],
        );

        return $this->file('src/data/site.ts', <<<TS
            /**
             * Facts about First Mile Manager that appear on many pages, in structured data and in
             * llms.txt. Keep them identical everywhere: AI assistants and search engines
             * quote them.
             */
            export const site = {
              name: {$facts['name']},
              shortName: 'First Mile Manager',
              url: {$facts['url']},
              tagline: 'Collect from your clients, send it on.',
              description:
                {$facts['description']},
              email: 'hello@firstmilemanager.co.uk',
              stage: {$facts['stage']},

              /** The company behind First Mile Manager. */
              company: {
                legalName: 'Gotham Projects Ltd',
                address: { street: '1 Crow Hill Drive', town: 'Mansfield', postcode: 'NG19 7AE', country: 'GB' },
              },

              // The public Trentside Logistics demo, not the marketing site.
              demo: {
                live: false,
                url: 'https://demo.firstmilemanager.co.uk',
              },

              helpUrl: null as string | null,
            };

            export const nav = [
              { label: 'Product', href: '/product' },
            ];

            TS);
    }

    /** Writes any file into the folder, exactly as given. */
    public function file(string $relativePath, string $contents): self
    {
        File::ensureDirectoryExists(dirname($this->path.'/'.$relativePath));
        File::put($this->path.'/'.$relativePath, $contents);

        return $this;
    }

    public function path(): string
    {
        return $this->path;
    }
}
