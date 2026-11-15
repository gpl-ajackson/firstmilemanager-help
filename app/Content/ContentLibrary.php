<?php

namespace App\Content;

use App\Pictures\PictureLibrary;
use App\Renderer\Links\LinkingPage;
use App\Renderer\Links\LinksByName;
use App\Renderer\Links\LinkTargets;
use App\Renderer\Links\NamedLink;
use App\Renderer\ParsedArticle;
use App\Renderer\RenderedArticle;
use App\Renderer\Renderer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use LogicException;
use Symfony\Component\Finder\Finder;

/**
 * The content library (spec #30): reads the content folder, checks its header data and
 * rebuilds the database copy of every Article, Glossary Entry and Release Note from it. The files are the
 * only source; nothing else writes the copy. Pages ask it for Articles and never see a Draft.
 *
 * Content folder layout (see the decisions log for what later tickets add):
 *
 *     content/articles/<Article Name>.md   one Article: header data, then Markdown
 *     content/glossary/<term>.md           one staff Glossary Entry (GlossaryEntrySource)
 *     content/client-glossary/<term>.md    one Client Glossary entry
 *     content/release-notes/<date>.md      one Release Note per FMM Release (ReleaseNoteSource)
 *     content/next-release/*.md            Change Summaries waiting for the next one: checked, never shown
 *
 * Writers link Articles, Sections and terms by name (`article:<name>#<section>`, `term:<term>`);
 * each body is rendered once every Article's Section Names are known, so its links resolve for
 * its readers. A link that leads nowhere shows as plain text and is listed, never refused: the
 * Links check (Launch Check 3) fails it.
 *
 * Addresses last (ADR-0001): every old address the folder names (an Article's former
 * addresses, a Retired Article's replacement, a renamed term's former names) is kept as a redirect, which the site follows
 * whenever an address has no page of its own.
 */
final class ContentLibrary
{
    public function __construct(
        private readonly Renderer $renderer,
        private readonly PictureLibrary $pictures,
    ) {}

    /**
     * Rebuilds the database copy from the content folder at $contentPath, rendering each
     * Article once with its Pictures from $picturesPath (default: config help.pictures_path)
     * and its diagrams drawn. Nothing changes unless every file is valid. $everyFeatureOnDemo
     * shows the Articles still waiting for the demo, as the Findability check searches them.
     *
     * @throws InvalidContent naming each file and field that is wrong.
     */
    public function rebuild(string $contentPath, ?string $picturesPath = null, bool $everyFeatureOnDemo = false): RebuildSummary
    {
        [$articles, $bodies, $entries, $entryBodies, $brokenLinks, $terms, , $facts, $areas, $notes, $noteRows, $waiting] = $this->readArticles(rtrim($contentPath, '/'), $this->rendererFor($picturesPath), $everyFeatureOnDemo);
        $redirects = $this->checkAddresses($articles, $entries, $areas, $notes);

        $rows = array_map(fn (ArticleSource $article): array => [
            'name' => $article->name,
            'title' => $article->title,
            'summary' => $article->summary,
            'type' => $article->type->value,
            'product_area' => $article->productArea,
            'audience_tier' => $article->audienceTier->value,
            'owner' => $article->owner,
            'status' => $article->status->value,
            'checked_on' => $article->checkedOn->toDateString(),
            'source_path' => $article->file,
            'body_html' => $bodies[$article->name]->html,
            'next_review_on' => $article->nextReviewOn->toDateString(),
            'team_roles' => json_encode($article->teamRoles),
            'source_references' => json_encode($article->sourceReferences),
            'related_articles' => json_encode($article->relatedArticles),
            'path_position' => $article->pathPosition,
            'messages' => json_encode($article->messages),
            'section_names' => json_encode((object) $bodies[$article->name]->sections),
            'what_changed' => $article->whatChanged,
            'terms' => json_encode($terms[$article->name]),
            'feature' => $article->feature,
            'joint_only' => $article->jointOnly,
            'waiting_for_demo' => $article->waitingForDemo,
        ], $articles);
        $entryRows = $this->entryRows($entries, $entryBodies);

        $areaRows = array_map(fn (ProductAreaSource $area, int $index): array => ['name' => $area->name, 'position' => $index + 1], $areas, array_keys($areas));

        DB::transaction(function () use ($rows, $entryRows, $redirects, $facts, $areaRows, $noteRows): void {
            Facts::query()->delete();
            Facts::query()->insert($facts->row());
            ProductArea::query()->delete();
            ProductArea::query()->insert($areaRows);
            Article::query()->delete();
            foreach (array_chunk($rows, 100) as $chunk) {
                Article::query()->insert($chunk);
            }
            GlossaryEntry::query()->delete();
            foreach (array_chunk($entryRows, 100) as $chunk) {
                GlossaryEntry::query()->insert($chunk);
            }
            ReleaseNote::query()->delete();
            ReleaseNote::query()->insert($noteRows);
            Redirect::query()->delete();
            foreach (array_chunk($redirects->rows(), 100) as $chunk) {
                Redirect::query()->insert($chunk);
            }
        });

        $count = fn (ArticleStatus $status): int => count(array_filter($articles, fn (ArticleSource $a): bool => $a->status === $status));

        return new RebuildSummary(
            published: $count(ArticleStatus::Published),
            drafts: count(array_filter($articles, fn (ArticleSource $a): bool => $a->status === ArticleStatus::Draft && ! $a->waitingForDemo)),
            retired: $count(ArticleStatus::Retired),
            brokenLinks: $brokenLinks,
            glossaryEntries: count(array_filter($entries, fn (GlossaryEntrySource $e): bool => $e->audienceTier === AudienceTier::Staff)),
            clientGlossaryEntries: count(array_filter($entries, fn (GlossaryEntrySource $e): bool => $e->audienceTier === AudienceTier::Client)),
            releaseNotes: count($notes),
            changeSummaries: $waiting,
            waitingForDemo: count(array_filter($articles, fn (ArticleSource $a): bool => $a->waitingForDemo)),
        );
    }

    /**
     * Reads the content folder at $contentPath (with its Pictures from $picturesPath, default
     * config help.pictures_path) as a rebuild would, without changing anything: every problem
     * a rebuild would refuse (check 1 reports them with the rest of its findings), every link by
     * name that leads nowhere (the Links check fails them, though a rebuild only lists them) and
     * what links by name may lead to (the Source References and Findability checks check names
     * against it). One read serves them all, since each renders every Article.
     */
    public function inspect(string $contentPath, ?string $picturesPath = null): ContentInspection
    {
        try {
            [$articles, , $entries, , $brokenLinks, , $targets, , $areas, $notes] = $this->readArticles(rtrim($contentPath, '/'), $this->rendererFor($picturesPath));
        } catch (InvalidContent $invalid) {
            return new ContentInspection($invalid->problems, [], null);
        }
        try {
            $this->checkAddresses($articles, $entries, $areas, $notes);
        } catch (InvalidContent $invalid) {
            return new ContentInspection($invalid->problems, $brokenLinks, $targets);
        }

        return new ContentInspection([], $brokenLinks, $targets);
    }

    /**
     * The help centre's facts (Stockist's name, description, stage and the marketing site's
     * address), as the marketing site states them; null before the first rebuild.
     */
    public function facts(): ?Facts
    {
        return Facts::query()->first();
    }

    /** A Published Article by its Article Name; null for a Draft, Retired or unknown name. */
    public function publishedArticle(string $name): ?Article
    {
        return Article::query()->published()->where('name', $name)->first();
    }

    /**
     * An Article by its Article Name whatever its status, for the help centre's own work (the
     * issue desk finds a voted-on Article's owner here even if it has been retired since). Never
     * for a page: pages take Articles from publishedArticle().
     */
    public function articleNamed(string $name): ?Article
    {
        return Article::query()->where('name', $name)->first();
    }

    /**
     * Every Article whatever its status, keyed by Article Name, for the help centre's own work
     * (the Help Gaps Report reads the tier of each Article viewed or voted on here). Never for a
     * page.
     *
     * @return Collection<string, Article>
     */
    public function articlesByName(): Collection
    {
        return Article::query()->get()->keyBy('name');
    }

    /**
     * A Draft Article by its Article Name, for a Help Preview's page only (App\Preview): the help
     * centre itself never shows a Draft.
     */
    public function draftArticle(string $name): ?Article
    {
        return Article::query()->where('status', ArticleStatus::Draft)->where('name', $name)->first();
    }

    /**
     * Every Draft Article, by title: what a Help Preview shows marked as drafts, and nothing else.
     *
     * @return Collection<int, Article>
     */
    public function draftArticles(): Collection
    {
        return Article::query()->where('status', ArticleStatus::Draft)->orderBy('title')->get();
    }

    /**
     * Every Published Article but the Joint-only ones, by title. Listings, the sitemap, llms.txt
     * and the search index take Articles from here, so a Draft, Retired or Joint-only Article is
     * never listed or searched.
     *
     * @return Collection<int, Article>
     */
    public function publishedArticles(): Collection
    {
        return Article::query()->listed()->orderBy('title')->get();
    }

    /**
     * The Product Areas that have a Published Article, and so appear on the site, in the app's
     * menu order (content/product-areas.yaml); any area it doesn't list comes after, by name.
     *
     * @return list<string>
     */
    public function shownProductAreas(): array
    {
        return array_keys($this->productAreaCounts());
    }

    /**
     * Each Product Area that has a Published Article, with how many it has (of both tiers), in
     * the app's menu order: the signs on the help home.
     *
     * @return array<string, int>
     */
    public function productAreaCounts(): array
    {
        $counts = [];
        foreach (Article::query()->listed()->get(['product_area']) as $article) {
            $counts[$article->product_area] = ($counts[$article->product_area] ?? 0) + 1;
        }

        $order = $this->menuOrder();
        uksort($counts, fn (string $a, string $b): int => [$order($a), $a] <=> [$order($b), $b]);

        return $counts;
    }

    /** The shown Product Area whose listing is at /areas/<$addressName>; null when none is. */
    public function shownProductArea(string $addressName): ?string
    {
        foreach ($this->shownProductAreas() as $area) {
            if (ProductAreaSource::addressOf($area) === '/areas/'.$addressName) {
                return $area;
            }
        }

        return null;
    }

    /**
     * Every Published Article in a Product Area, of both tiers, by Article Type in the
     * glossary's order (Getting-started in path order), then title.
     *
     * @return Collection<int, Article>
     */
    public function articlesInArea(string $productArea): Collection
    {
        return $this->inListingOrder(Article::query()->listed()->where('product_area', $productArea)->get());
    }

    /**
     * A Role Path: every Published Staff Article tagged with one of its Team Roles, grouped
     * by Product Area in menu order, Getting-started and How-to Articles first in each.
     *
     * @return array<string, Collection<int, Article>>
     */
    public function rolePath(RolePath $role): array
    {
        $articles = Article::query()->listed()
            ->where('audience_tier', AudienceTier::Staff)
            ->where(function (Builder $query) use ($role): void {
                foreach ($role->teamRoles() as $teamRole) {
                    $query->orWhereJsonContains('team_roles', $teamRole);
                }
            })
            ->get();

        return $this->byProductArea($articles);
    }

    /**
     * The Getting-started Path as readers meet it: the Published Getting-started Articles in
     * path order, so a Draft holding a place leaves no gap.
     *
     * @return Collection<int, Article>
     */
    public function gettingStartedPath(): Collection
    {
        return Article::query()->listed()->where('type', ArticleType::GettingStarted)->orderBy('path_position')->get();
    }

    /**
     * For your clients: every Published Client-tier Article, by Product Area in menu order,
     * then Article Type, then title.
     *
     * @return Collection<int, Article>
     */
    public function clientArticles(): Collection
    {
        return $this->inListingOrder(Article::query()->listed()->where('audience_tier', AudienceTier::Client)->get(), byArea: true);
    }

    /**
     * Every Published Troubleshooting Article, grouped by Product Area in menu order, by title.
     *
     * @return array<string, Collection<int, Article>>
     */
    public function troubleshootingArticles(): array
    {
        return $this->byProductArea(Article::query()->listed()->where('type', ArticleType::Troubleshooting)->get());
    }

    /**
     * Articles grouped by Product Area in menu order, each group in listing order.
     *
     * @param  Collection<int, Article>  $articles
     * @return array<string, Collection<int, Article>>
     */
    private function byProductArea(Collection $articles): array
    {
        $groups = [];
        foreach ($this->inListingOrder($articles, byArea: true) as $article) {
            $groups[$article->product_area] ??= new Collection;
            $groups[$article->product_area]->push($article);
        }

        return $groups;
    }

    /**
     * Articles in the order listings show them: (by Product Area in menu order, when asked,)
     * by Article Type in the glossary's order (Getting-started, How-to, Concept, Reference,
     * Troubleshooting, FAQ), Getting-started in path order, then by title.
     *
     * @param  Collection<int, Article>  $articles
     * @return Collection<int, Article>
     */
    private function inListingOrder(Collection $articles, bool $byArea = false): Collection
    {
        $order = $this->menuOrder();
        $key = fn (Article $article): array => [
            $byArea ? $order($article->product_area) : 0,
            $byArea ? $article->product_area : '',
            array_search($article->type, ArticleType::cases(), true),
            $article->path_position ?? 0,
            mb_strtolower($article->title),
        ];

        return $articles->sort(fn (Article $a, Article $b): int => $key($a) <=> $key($b))->values();
    }

    /**
     * A Product Area's place in the app's menu order; areas content/product-areas.yaml doesn't
     * list come after every listed one.
     *
     * @return \Closure(string): int
     */
    private function menuOrder(): \Closure
    {
        /** @var array<string, int> $positions */
        $positions = ProductArea::query()->pluck('position', 'name')->all();

        return fn (string $area): int => $positions[$area] ?? PHP_INT_MAX;
    }

    /** A Retired Article with no replacement, whose address shows a notice of what changed. */
    public function retiredArticle(string $name): ?Article
    {
        // Every Retired Article has either a replacement (and redirects) or says what changed.
        return Article::query()
            ->where('status', ArticleStatus::Retired)
            ->whereNotNull('what_changed')
            ->where('name', $name)
            ->first();
    }

    /**
     * Every Retired Article with no replacement, by name: each address shows a notice of what
     * changed (a Help Preview copies the notices).
     *
     * @return Collection<int, Article>
     */
    public function retiredNotices(): Collection
    {
        return Article::query()
            ->where('status', ArticleStatus::Retired)
            ->whereNotNull('what_changed')
            ->orderBy('name')
            ->get();
    }

    /** Whether a Product Area has a Published Article, and so appears on the site. */
    public function productAreaIsShown(string $productArea): bool
    {
        return Article::query()->listed()->where('product_area', $productArea)->exists();
    }

    /**
     * Where an old address now leads, for good (ADR-0001): an Article's former address, a
     * Retired Article with a replacement, and any other old address the content folder keeps.
     * Null for an address that never redirected.
     */
    public function redirectFor(string $path): ?string
    {
        return Redirect::query()->where('from_path', $path)->first()?->to_path;
    }

    /**
     * Every old address the content keeps, with where it leads now (a Help Preview writes them
     * as Cloudflare Pages redirects).
     *
     * @return array<string, string> from => to
     */
    public function redirects(): array
    {
        /** @var array<string, string> */
        return Redirect::query()->orderBy('from_path')->pluck('to_path', 'from_path')->all();
    }

    /**
     * The Release Notes, newest first: all of them, or the latest $limit (What's new on the help
     * home). A Release Note is history, so every one in the content folder is published.
     *
     * @return Collection<int, ReleaseNote>
     */
    public function releaseNotes(?int $limit = null): Collection
    {
        return ReleaseNote::query()->orderByDesc('released_on')->when($limit !== null, fn (Builder $query) => $query->limit((int) $limit))->get();
    }

    /**
     * The Change Summaries waiting in the Next Release folder of $contentPath, checked as the
     * rebuild checks them, by Product Area in the app's menu order (content/product-areas.yaml;
     * areas it doesn't list after, by name), then by file name. Reads only the files.
     *
     * @return list<ChangeSummarySource>
     *
     * @throws InvalidContent naming each Change Summary and field that is wrong.
     */
    public function nextRelease(string $contentPath): array
    {
        $problems = [];
        $contentPath = rtrim($contentPath, '/');
        $waiting = ChangeSummarySource::readAll($contentPath, $problems);
        $areas = ProductAreaSource::readAll($contentPath, $problems);
        if ($problems !== []) {
            throw new InvalidContent($problems);
        }

        $order = array_flip(array_map(fn (ProductAreaSource $area): string => $area->name, $areas));
        $key = fn (ChangeSummarySource $source): array => [$order[$source->summary->productArea] ?? PHP_INT_MAX, $source->summary->productArea, $source->file];
        usort($waiting, fn (ChangeSummarySource $a, ChangeSummarySource $b): int => $key($a) <=> $key($b));

        return $waiting;
    }

    /** The Release Note of the FMM Release on $date (YYYY-MM-DD); null when there is none. */
    public function releaseNote(string $date): ?ReleaseNote
    {
        return ReleaseNote::query()->whereDate('released_on', $date)->first();
    }

    /** A Glossary Entry by its name in its address, in the staff Glossary or the Client Glossary. */
    public function glossaryEntry(string $name, AudienceTier $tier): ?GlossaryEntry
    {
        return GlossaryEntry::query()->where('audience_tier', $tier)->where('name', $name)->first();
    }

    /**
     * Every Glossary Entry A to Z by term: one tier's (the Glossary, or the Client Glossary), or
     * both. Listings and the search index take entries from here.
     *
     * @return Collection<int, GlossaryEntry>
     */
    public function glossaryEntries(?AudienceTier $tier = null): Collection
    {
        return GlossaryEntry::query()
            ->when($tier !== null, fn (Builder $query) => $query->where('audience_tier', $tier))
            ->get()
            ->sortBy('term', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    /**
     * "The Articles that use it": the Published Articles whose readers reach this entry from a
     * link in their body, by title. Worked out from the links, never written by hand; a term
     * link on a Client-tier Article leads to the Client Glossary, so a client entry lists only
     * Client-tier Articles and a staff entry only Staff ones.
     *
     * @return Collection<int, Article>
     */
    public function articlesUsing(GlossaryEntry $entry): Collection
    {
        return Article::query()->listed()
            ->where('audience_tier', $entry->audience_tier)
            ->whereJsonContains('terms', $entry->name)
            ->orderBy('title')
            ->get();
    }

    /**
     * A Published Article's related articles that a reader of it may open, in the writer's
     * order: Published, never itself, and on a Client-tier Article only Client-tier ones.
     *
     * @return Collection<int, Article>
     */
    public function relatedArticles(Article $article): Collection
    {
        $names = array_values(array_diff($article->related_articles, [$article->name]));

        return Article::query()->published()->whereIn('name', $names)
            ->unless($article->joint_only, fn (Builder $query) => $query->where('joint_only', false))
            ->when($article->audience_tier === AudienceTier::Client,
                fn (Builder $query) => $query->where('audience_tier', AudienceTier::Client))
            ->get()
            ->sortBy(fn (Article $related): int => (int) array_search($related->name, $names, true))
            ->values();
    }

    /**
     * A Published Getting-started Article's place in the Getting-started Path as a reader
     * meets it: its step among the Published Getting-started Articles in path order, and the
     * next one. Null for any other type.
     */
    public function placeInPath(Article $article): ?PlaceInPath
    {
        if ($article->type !== ArticleType::GettingStarted) {
            return null;
        }
        $path = $this->gettingStartedPath();
        $index = $path->search(fn (Article $step): bool => $step->name === $article->name);
        if ($index === false) {
            return null;
        }

        return new PlaceInPath(step: $index + 1, of: $path->count(), next: $path->get($index + 1));
    }

    /**
     * Checks the addresses across the whole folder (ADR-0001): a Retired Article's
     * replacement is a Published Article for the same readers, and a former address (an
     * Article's, or a renamed term's old Glossary address) is never in use or claimed twice.
     *
     * @param  list<ArticleSource>  $articles
     * @param  list<GlossaryEntrySource>  $entries
     * @param  list<ProductAreaSource>  $areas
     * @param  list<ReleaseNoteSource>  $notes
     *
     * @throws InvalidContent
     */
    private function checkAddresses(array $articles, array $entries, array $areas, array $notes): RedirectMap
    {
        $byName = [];
        foreach ($articles as $article) {
            $byName[$article->name] = $article;
        }

        $map = new RedirectMap;
        $problems = [];
        foreach ($articles as $article) {
            if ($article->replacement === null) {
                $map->uses($article->address(), $article->file, served: $article->status !== ArticleStatus::Draft);
            } else {
                $replacement = $byName[$article->replacement] ?? null;
                if ($replacement === null) {
                    $problems[] = new ContentProblem($article->file, 'replacement', "there is no Article named \"{$article->replacement}\"");
                } elseif ($replacement->status !== ArticleStatus::Published) {
                    $problems[] = new ContentProblem($article->file, 'replacement', "\"{$article->replacement}\" is {$replacement->status->value}; name a Published Article");
                } elseif ($article->audienceTier === AudienceTier::Client && $replacement->audienceTier !== AudienceTier::Client) {
                    $problems[] = new ContentProblem($article->file, 'replacement', "\"{$article->replacement}\" is for Staff; a Client-tier Article is replaced only by a Client-tier Article");
                }
                $map->redirect($article->address(), $replacement?->address() ?? ArticleSource::addressOf($article->replacement), $article->file, 'replacement');
            }

            foreach ($article->formerAddresses as $address) {
                $map->redirect($address, $article->address(), $article->file, 'former_addresses');
            }
        }

        // A renamed term's entry follows its current name; each old name's address leads to it.
        foreach ($entries as $entry) {
            $map->uses($entry->address(), $entry->file);
            foreach ($entry->formerNames as $former) {
                $map->redirect(GlossaryEntrySource::addressOf(GlossaryEntrySource::addressNameOf($former), $entry->audienceTier),
                    $entry->address(), $entry->file, 'former_names');
            }
        }

        // A Product Area's listing follows the app's menu: an area is in use while any Article
        // names it (shown once one is Published), and a renamed area's old addresses lead to it.
        $shown = [];
        foreach ($areas as $area) {
            $shown[$area->name] = ['file' => ProductAreaSource::FILE, 'served' => false];
        }
        foreach ($articles as $article) {
            $shown[$article->productArea] ??= ['file' => $article->file, 'served' => false];
            $shown[$article->productArea]['served'] = $shown[$article->productArea]['served'] || $article->status === ArticleStatus::Published;
        }
        foreach ($shown as $name => ['file' => $file, 'served' => $served]) {
            $map->uses(ProductAreaSource::addressOf($name), $file, served: $served);
        }
        foreach ($areas as $area) {
            foreach ($area->formerNames as $former) {
                $map->redirect(ProductAreaSource::addressOf($former), $area->address(), ProductAreaSource::FILE, 'former_names');
            }
        }

        foreach ($notes as $note) {
            $map->uses($note->address(), $note->file);
        }

        $problems = [...$problems, ...$map->problems()];
        if ($problems !== []) {
            throw new InvalidContent($problems);
        }

        return $map;
    }

    /**
     * Every Article and Glossary Entry in the folder, checked and rendered, or every problem
     * found, with the links by name that lead nowhere (in a body or an Article's related
     * articles) and the terms each Article links to (each term's first use, resolved).
     *
     * Release Notes come back as their database copy's rows, rendered and grouped; the Change
     * Summaries waiting in the Next Release folder are checked and counted, never shown.
     *
     * @return array{list<ArticleSource>, array<string, RenderedArticle>, list<GlossaryEntrySource>, array<string, RenderedArticle>, list<BrokenLink>, array<string, list<string>>, LinkTargets, FactsSource, list<ProductAreaSource>, list<ReleaseNoteSource>, list<array<string, mixed>>, int}
     *
     * @throws InvalidContent
     */
    private function readArticles(string $contentPath, Renderer $renderer, bool $everyFeatureOnDemo = false): array
    {
        $problems = [];
        if (! is_dir($contentPath.'/articles')) {
            throw new InvalidContent([new ContentProblem($contentPath, 'articles', 'the content folder has no articles folder')]);
        }

        $files = Finder::create()->files()->in($contentPath.'/articles')->depth(0)->sortByName();
        $articles = [];
        $parsed = [];
        foreach ($files as $file) {
            $relative = 'articles/'.$file->getFilename();
            if ($file->getExtension() !== 'md') {
                $problems[] = new ContentProblem($relative, 'file', 'only Markdown files ending ".md" belong in the articles folder');

                continue;
            }
            $article = ArticleSource::read($file->getPathname(), $relative, $problems);
            if ($article === null) {
                continue;
            }
            $body = $renderer->parse($article->body, $article->messages);
            foreach ($body->problems as $problem) {
                $problems[] = new ContentProblem($relative, 'Section Name', $problem);
            }
            foreach ($body->pictureProblems as $problem) {
                $problems[] = new ContentProblem($relative, $problem['field'], 'line '.($article->bodyLine + $problem['line'] - 1).": {$problem['message']}");
            }
            $articles[] = $article;
            $parsed[$article->name] = $body;
        }

        // An Article goes public only once its feature is on the demo (ADR-0006); till then it waits as a Draft.
        $features = FeatureList::read($contentPath, $problems);
        foreach ($articles as $index => $article) {
            if ($article->feature === null) {
                continue;
            }
            if (! $features->has($article->feature)) {
                $problems[] = new ContentProblem($article->file, 'feature', "\"{$article->feature}\" is not a feature in ".FeatureList::FILE.': name one it lists, or add it there with its spec and on_demo');
            } elseif ($article->status === ArticleStatus::Published && ! $everyFeatureOnDemo && ! $features->isOnDemo($article->feature)) {
                $articles[$index] = $article->waitingForDemo();
            }
        }

        array_push($problems, ...$this->pathProblems($articles));
        $entries = $this->readEntries($contentPath, $problems);
        $facts = FactsSource::read($contentPath, $problems);
        $areas = ProductAreaSource::readAll($contentPath, $problems);
        $notes = ReleaseNoteSource::readAll($contentPath, $problems);
        $waiting = ChangeSummarySource::readAll($contentPath, $problems);

        if ($problems !== []) {
            throw new InvalidContent($problems);
        }

        // Rendered now every Article's Section Names are known, so links resolve.
        $targets = $this->linkTargets($articles, $parsed, $entries, $notes);
        $bodies = [];
        $brokenLinks = [];
        $terms = [];
        foreach ($articles as $article) {
            $links = new LinksByName($targets, new LinkingPage(
                name: $article->name,
                clientTier: $article->audienceTier === AudienceTier::Client,
                draft: $article->status === ArticleStatus::Draft,
                jointOnly: $article->jointOnly,
            ));
            $bodies[$article->name] = $renderer->render($parsed[$article->name], $links);
            $terms[$article->name] = array_values(array_unique(array_map(fn (NamedLink $link): string => (string) $link->name,
                array_filter($bodies[$article->name]->links, fn (NamedLink $link): bool => $link->kind === NamedLink::TERM && $link->address !== null))));

            // A Retired Article's body is never shown, so its links lead nobody astray.
            if ($article->status === ArticleStatus::Retired) {
                continue;
            }
            foreach ($article->relatedArticles as $name) {
                $related = $links->relatedArticle($name, $article->lineOfItem('related_articles', $name));
                if ($related->problem !== null) {
                    $brokenLinks[] = new BrokenLink($article->file, $related->line, 'related_articles', $name, $related->problem);
                }
            }
            foreach ($bodies[$article->name]->links as $link) {
                if ($link->problem !== null) {
                    $brokenLinks[] = new BrokenLink($article->file, $article->bodyLine + $link->line - 1, 'link', $link->written, $link->problem);
                }
            }
        }

        // An entry's body links for its own tier's readers: a Client Glossary entry only to client pages.
        $entryBodies = [];
        foreach ($entries as $entry) {
            $links = new LinksByName($targets, new LinkingPage(name: '', clientTier: $entry->audienceTier === AudienceTier::Client, draft: false, jointOnly: false));
            $body = $renderer->article($entry->body, [], $links);
            foreach ($body->problems as $problem) {
                $problems[] = new ContentProblem($entry->file, 'Section Name', $problem);
            }
            foreach ($body->links as $link) {
                if ($link->problem !== null) {
                    $brokenLinks[] = new BrokenLink($entry->file, $entry->bodyLine + $link->line - 1, 'link', $link->written, $link->problem);
                }
            }
            $entryBodies[$entry->address()] = $body;
        }
        $noteRows = $this->releaseNoteRows($notes, $waiting, $areas, $targets, $renderer, $brokenLinks, $problems);
        if ($problems !== []) {
            throw new InvalidContent($problems);
        }

        // FactsSource::read() gives no facts only after reporting why, so this never throws.
        $facts ?? throw new LogicException('The facts were not read, and no problem says why.');

        return [$articles, $bodies, $entries, $entryBodies, $brokenLinks, $terms, $targets, $facts, $areas, $notes, $noteRows, count($waiting)];
    }

    /**
     * Every Glossary Entry in the folder, staff (content/glossary/) and client
     * (content/client-glossary/), each checked on its own; problems are added to $problems.
     *
     * @param  list<ContentProblem>  $problems
     * @return list<GlossaryEntrySource>
     */
    private function readEntries(string $contentPath, array &$problems): array
    {
        $entries = [];
        foreach (GlossaryEntrySource::FOLDERS as $folder => $tier) {
            if (! is_dir("{$contentPath}/{$folder}")) {
                continue;
            }
            foreach (Finder::create()->files()->in("{$contentPath}/{$folder}")->depth(0)->sortByName() as $file) {
                $relative = "{$folder}/".$file->getFilename();
                if ($file->getExtension() !== 'md') {
                    $problems[] = new ContentProblem($relative, 'file', "only Markdown files ending \".md\" belong in the {$folder} folder");

                    continue;
                }
                $entry = GlossaryEntrySource::read($file->getPathname(), $relative, $tier, $problems);
                if ($entry !== null) {
                    $entries[] = $entry;
                }
            }
        }

        return $entries;
    }

    /**
     * The database copy's rows for the Release Notes: each change rendered for Staff (Release
     * Notes are staff-only) with the Articles it changed, grouped by Product Area in the app's
     * menu order. The Change Summaries waiting for the next release are rendered the same way,
     * only so their links are checked on the Help Proposal that adds them.
     *
     * @param  list<ReleaseNoteSource>  $notes
     * @param  list<ChangeSummarySource>  $waiting
     * @param  list<ProductAreaSource>  $areas
     * @param  list<BrokenLink>  $brokenLinks
     * @param  list<ContentProblem>  $problems
     * @return list<array<string, mixed>>
     */
    private function releaseNoteRows(array $notes, array $waiting, array $areas, LinkTargets $targets, Renderer $renderer, array &$brokenLinks, array &$problems): array
    {
        $links = new LinksByName($targets, new LinkingPage(name: '', clientTier: false, draft: false));
        $render = function (ChangeSummary $change, string $file, callable $lineOfLink, callable $lineOfArticle) use ($links, $targets, $renderer, &$brokenLinks, &$problems): array {
            $text = $renderer->article($change->text, [], $links);
            foreach ($text->problems as $problem) {
                $problems[] = new ContentProblem($file, 'Section Name', $problem);
            }
            foreach ($text->links as $link) {
                if ($link->problem !== null) {
                    $brokenLinks[] = new BrokenLink($file, $lineOfLink($link), 'link', $link->written, $link->problem);
                }
            }
            $articles = [];
            foreach ($change->articles as $name) {
                $article = $links->relatedArticle($name, $lineOfArticle($name));
                if ($article->problem !== null || $article->address === null) {
                    $brokenLinks[] = new BrokenLink($file, $article->line, 'articles', $name, (string) $article->problem);

                    continue;
                }
                $articles[] = ['title' => $targets->findArticle($name)['title'] ?? $name, 'address' => $article->address];
            }

            return ['html' => $text->html, 'team_roles' => $change->teamRoles, 'affects_clients' => $change->affectsClients, 'articles' => $articles];
        };

        foreach ($waiting as $summary) {
            $render($summary->summary, $summary->file,
                fn (NamedLink $link): int => $summary->bodyLine + $link->line - 1,
                fn (string $name): int => $summary->lineOfArticle($name));
        }

        $order = array_flip(array_map(fn (ProductAreaSource $area): string => $area->name, $areas));
        $rows = [];
        foreach ($notes as $note) {
            $intro = $renderer->article($note->body, [], $links);
            foreach ($intro->problems as $problem) {
                $problems[] = new ContentProblem($note->file, 'Section Name', $problem);
            }
            foreach ($intro->links as $link) {
                if ($link->problem !== null) {
                    $brokenLinks[] = new BrokenLink($note->file, $note->bodyLine + $link->line - 1, 'link', $link->written, $link->problem);
                }
            }

            $byArea = [];
            $listed = [];
            foreach ($note->changes as $change) {
                $byArea[$change->productArea][] = $render($change, $note->file,
                    fn (NamedLink $link): int => $note->lineOf($link->written),
                    function (string $name) use ($note, &$listed): int {
                        $listed[$name] = ($listed[$name] ?? 0) + 1;

                        return $note->lineOfArticle($name, $listed[$name]);
                    });
            }
            uksort($byArea, fn (string $a, string $b): int => [$order[$a] ?? PHP_INT_MAX, $a] <=> [$order[$b] ?? PHP_INT_MAX, $b]);

            $rows[] = [
                'released_on' => $note->date->toDateString(),
                'summary' => $note->summary,
                'intro_html' => $note->body === '' ? '' : $intro->html,
                'areas' => json_encode(array_map(fn (string $area, array $changes): array => ['area' => $area, 'changes' => $changes], array_keys($byArea), $byArea)),
                'affects_clients' => array_any($note->changes, fn (ChangeSummary $change): bool => $change->affectsClients),
                'source_path' => $note->file,
            ];
        }

        return $rows;
    }

    /**
     * The database copy's rows for the Glossary Entries. A status value that is a term in its
     * own right (On Hold) links to its own entry for the same readers.
     *
     * @param  list<GlossaryEntrySource>  $entries
     * @param  array<string, RenderedArticle>  $bodies  By the entry's address.
     * @return list<array<string, mixed>>
     */
    private function entryRows(array $entries, array $bodies): array
    {
        $byTerm = [];
        foreach ($entries as $entry) {
            $byTerm[$entry->audienceTier->value][mb_strtolower($entry->term)] = $entry->address();
        }

        return array_map(fn (GlossaryEntrySource $entry): array => [
            'name' => $entry->name,
            'audience_tier' => $entry->audienceTier->value,
            'term' => $entry->term,
            'other_names' => json_encode($entry->otherNames),
            'former_names' => json_encode($entry->formerNames),
            'where_you_see_it' => $entry->whereYouSeeIt,
            'status_values' => json_encode(array_map(fn (array $value): array => [
                ...$value,
                'address' => $byTerm[$entry->audienceTier->value][mb_strtolower($value['value'])] ?? null,
            ], $entry->statusValues)),
            'checked_on' => $entry->checkedOn->toDateString(),
            'source_path' => $entry->file,
            'body_html' => $bodies[$entry->address()]->html,
        ], $entries);
    }

    /**
     * What links by name may lead to: every Article with its Section Names, and every term's
     * Glossary Entry by tier; and the other pages a writer may link by path: every page whose
     * route has no name in it (routes/public.php: /getting-started, /glossary, /search, ...),
     * each Role Path, each Release Note, and each Product Area with an Article that isn't
     * Retired (shown once one is Published).
     *
     * @param  list<ArticleSource>  $articles
     * @param  array<string, ParsedArticle>  $parsed
     * @param  list<GlossaryEntrySource>  $entries
     * @param  list<ReleaseNoteSource>  $notes
     */
    private function linkTargets(array $articles, array $parsed, array $entries, array $notes): LinkTargets
    {
        $targets = new LinkTargets;
        // Pages with no name in their address (/getting-started, /glossary, /search, ...).
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (in_array('GET', $route->methods(), true) && $route->parameterNames() === []) {
                $targets->page('/'.ltrim($route->uri(), '/'));
            }
        }
        foreach (RolePath::cases() as $role) {
            $targets->page('/roles/'.$role->value);
        }
        foreach ($notes as $note) {
            $targets->page($note->address());
        }
        foreach ($articles as $article) {
            if ($article->status !== ArticleStatus::Retired) {
                $targets->page(ProductAreaSource::addressOf($article->productArea), published: $article->status === ArticleStatus::Published && ! $article->jointOnly);
            }
        }
        foreach ($articles as $article) {
            $targets->article($article->name, $article->address(), $article->title, $parsed[$article->name]->sections,
                clientTier: $article->audienceTier === AudienceTier::Client,
                status: match ($article->status) {
                    ArticleStatus::Published => 'Published',
                    ArticleStatus::Draft => 'Draft',
                    ArticleStatus::Retired => 'Retired',
                },
                jointOnly: $article->jointOnly,
                waitingForDemo: $article->waitingForDemo);
        }
        foreach ($entries as $entry) {
            $targets->term($entry->name, $entry->address(), $entry->audienceTier === AudienceTier::Client, words: $entry->term);
        }

        return $targets;
    }

    /** The Renderer, showing Pictures from $picturesPath (default: config help.pictures_path). */
    private function rendererFor(?string $picturesPath): Renderer
    {
        return $this->renderer->withPictures($picturesPath === null ? $this->pictures : $this->pictures->from($picturesPath));
    }

    /**
     * Two Published Getting-started Articles can't share a place in the path. A Draft may, so a
     * replacement can be written beside the step it replaces.
     *
     * @param  list<ArticleSource>  $articles
     * @return list<ContentProblem>
     */
    private function pathProblems(array $articles): array
    {
        $holders = [];
        foreach ($articles as $article) {
            if ($article->pathPosition !== null && $article->status === ArticleStatus::Published) {
                $holders[$article->pathPosition][] = $article;
            }
        }

        $problems = [];
        foreach ($holders as $position => $sharing) {
            foreach ($sharing as $article) {
                $others = array_map(fn (ArticleSource $other): string => $other->name,
                    array_filter($sharing, fn (ArticleSource $other): bool => $other !== $article));
                if ($others !== []) {
                    $problems[] = new ContentProblem($article->file, 'path_position', "{$position} is also the place of ".implode(', ', $others));
                }
            }
        }

        return $problems;
    }
}
