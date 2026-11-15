<?php

namespace App\LaunchChecks\Indexing;

/**
 * A checkout of the marketing site (gpl-ajackson/firstmilemanager-marketing) at main, read as text
 * and never run: its src/data/site.ts holds the facts about Stockist both sites state (name,
 * description, stage, address), which the help centre copies into content/facts.yaml.
 */
final readonly class MarketingSite
{
    /** The marketing site's facts file. */
    public const SITE_TS = 'src/data/site.ts';

    public function __construct(public string $path) {}

    /**
     * The `site` object's own facts (`export const site = { ... }`), each a piece of quoted
     * text; a fact written any other way (joined from pieces, worked out, null) is null, and so
     * is one the object doesn't have. Null when there is no such object to read.
     *
     * @param  list<string>  $names
     * @return array<string, string|null>|null
     */
    public function facts(array $names): ?array
    {
        $file = $this->path.'/'.self::SITE_TS;
        if (! is_file($file)) {
            return null;
        }
        $site = TypeScriptObject::exported((string) file_get_contents($file), 'site');
        if ($site === null) {
            return null;
        }

        $facts = [];
        foreach ($names as $name) {
            $facts[$name] = is_string($site[$name] ?? null) ? $site[$name] : null;
        }

        return $facts;
    }
}
