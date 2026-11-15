<?php

namespace App\LaunchChecks;

use App\Content\ContentInspection;
use App\Content\ContentLibrary;
use App\Content\ContentProblem;
use App\Content\GlossaryEntrySource;
use App\LaunchChecks\Glossary\MainGlossary;
use App\LaunchChecks\Pages\PageChecks;
use App\LaunchChecks\Pages\PagesRun;
use App\LaunchChecks\SourceReferences\MainProjectIssues;
use Symfony\Component\Finder\Finder;

/**
 * What one run of the Launch Checks checks: a content folder, a checkout of the main project at
 * the ref under test (with its issues on GitHub, when there is a key to read them), the Article
 * Templates and Style Guide, a checkout of the marketing site at main, and, on a pull request,
 * its description. At launch, the rules that hold only from launch on (no built feature still
 * listing a new: Article, every term with a Term Status) fail instead of being reported.
 */
final class CheckContext
{
    /** @var list<ArticleFile>|null */
    private ?array $articles = null;

    /** @var list<ArticleFile>|null */
    private ?array $glossaryEntries = null;

    private ?ContentInspection $content = null;

    private ?PagesRun $pages = null;

    public function __construct(
        public readonly string $contentPath,
        public readonly MainProject $mainProject,
        public readonly string $templatesPath,
        public readonly ?string $pullRequestDescription = null,
        public readonly ?GitHubAccounts $github = null,
        public readonly bool $atLaunch = false,
        public readonly ?MainProjectIssues $issues = null,
        /** The Pictures and their Recipes; null for the repository's own (config help.pictures_path). */
        public readonly ?string $picturesPath = null,
        /** The Style Guide (docs/writing/style-guide.md), whose header data lists its banned words. */
        public readonly ?string $styleGuidePath = null,
        /**
         * Launch Check 12's retake (help:launch-checks --take-pictures): null for none, `changed`
         * for the Pictures added or changed since $picturesBase (a git ref), `all` for every one.
         * Pictures are taken from the main project's checkout, at its HEAD.
         */
        public readonly ?string $takePictures = null,
        public readonly ?string $picturesBase = null,
        /** Where the Picture Check leaves before, after and difference images; null for storage/pictures/check. */
        public readonly ?string $pictureArtefacts = null,
        /** A checkout of the marketing site at main, whose src/data/site.ts the facts are checked against (Launch Check 9). */
        public readonly ?string $marketingSitePath = null,
        /**
         * Launch Checks 7, 8 and 11 (help:launch-checks --pages): headless Chromium over every
         * published page of the content under test, served by the app itself.
         */
        public readonly bool $checkPages = false,
        /**
         * The real address to time each page template at too (help:launch-checks --speed-at),
         * through Cloudflare Access with its service token while the help centre is behind it.
         */
        public readonly ?string $speedAt = null,
        /**
         * A checkout of Stockist (gpl-ajackson/stockist) at main, whose GLOSSARY.md the Glossary
         * and Words checks read for the terms both apps share (help:launch-checks --stockist).
         */
        public readonly ?string $stockistPath = null,
    ) {}

    /** Stockist's GLOSSARY.md once read: false until then, null when there is no checkout or no file. */
    private MainGlossary|false|null $stockistGlossary = false;

    /** Stockist's GLOSSARY.md, for the terms both apps share; null when no checkout was given or it has none. */
    public function stockistGlossary(): ?MainGlossary
    {
        if ($this->stockistGlossary === false) {
            $this->stockistGlossary = $this->stockistPath === null ? null : MainGlossary::read($this->stockistPath.'/'.MainGlossary::FILE);
        }

        return $this->stockistGlossary;
    }

    /**
     * Every Markdown file in the content folder's articles folder, drafts and retired included.
     *
     * @return list<ArticleFile>
     */
    public function articles(): array
    {
        if ($this->articles !== null) {
            return $this->articles;
        }
        if (! is_dir($this->contentPath.'/articles')) {
            return $this->articles = [];
        }

        $articles = [];
        foreach (Finder::create()->files()->name('*.md')->in($this->contentPath.'/articles')->depth(0)->sortByName() as $file) {
            $articles[] = ArticleFile::read($file->getPathname(), 'articles/'.$file->getFilename());
        }

        return $this->articles = $articles;
    }

    /**
     * Every Glossary Entry file, staff entries (glossary/) then Client Glossary entries
     * (client-glossary/), each read like an Article file: header data with its lines, then body.
     *
     * @return list<ArticleFile>
     */
    public function glossaryEntries(): array
    {
        if ($this->glossaryEntries !== null) {
            return $this->glossaryEntries;
        }

        $entries = [];
        foreach (array_keys(GlossaryEntrySource::FOLDERS) as $folder) {
            if (! is_dir($this->contentPath.'/'.$folder)) {
                continue;
            }
            foreach (Finder::create()->files()->name('*.md')->in($this->contentPath.'/'.$folder)->depth(0)->sortByName() as $file) {
                $entries[] = ArticleFile::read($file->getPathname(), $folder.'/'.$file->getFilename());
            }
        }

        return $this->glossaryEntries = $entries;
    }

    /**
     * The content folder read as a rebuild would read it, once per run: every check that needs
     * its problems, links or link targets shares this one read (each read renders every Article).
     */
    public function content(): ContentInspection
    {
        return $this->content ??= app(ContentLibrary::class)->inspect($this->contentPath, $this->picturesPath);
    }

    /**
     * What a rebuild of the content folder would refuse (the content library's own rules).
     *
     * @return list<ContentProblem>
     */
    public function contentProblems(): array
    {
        return $this->content()->problems;
    }

    /**
     * What the page checks saw of the content under test (Launch Checks 7, 8 and 11): loaded,
     * visited and timed once, then shared by the three checks.
     */
    public function pages(): PagesRun
    {
        return $this->pages ??= app(PageChecks::class)->run($this);
    }

    /** Whether a rebuild refuses this field of this file, so a check names it only once. */
    public function refuses(string $file, string $field): bool
    {
        foreach ($this->contentProblems() as $problem) {
            if ($problem->file === $file && $problem->field === $field) {
                return true;
            }
        }

        return false;
    }

    /** A file's path as findings name it: relative to the app's folder when inside it. */
    public static function displayPath(string $path): string
    {
        $base = rtrim(base_path(), '/').'/';

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
