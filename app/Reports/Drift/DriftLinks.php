<?php

namespace App\Reports\Drift;

use App\LaunchChecks\Finding;
use App\LaunchChecks\Glossary\MainGlossary;
use App\LaunchChecks\SourceReferences\MainProjectIssues;

/**
 * Where each item of the Help Drift Report links to on GitHub: this repository's files at the
 * commit the report ran on (content/ and pictures/), the main project's files and history at
 * the commit it read, its issues and pull requests, and the workflow run that holds the
 * Picture Check's images.
 */
final readonly class DriftLinks
{
    private const GITHUB = 'https://github.com/';

    public function __construct(
        /** This repository, owner/name. */
        public string $supportSite,
        /** The commit or branch of this repository the report read. */
        public string $supportSiteRef,
        /** The main project's commit the report read (or a branch, when there is no history). */
        public string $mainProjectCommit,
        /** The workflow run, whose artefacts hold the Picture Check's images; null when run by hand. */
        public ?string $runUrl = null,
    ) {}

    /** A file of the content folder ("articles/x.md") or the pictures folder ("pictures/x.png"), at a line. */
    public function supportSiteFile(string $file, ?int $line = null): string
    {
        $path = str_starts_with($file, 'pictures/') || $file === 'pictures' ? $file : 'content/'.$file;

        return self::GITHUB.$this->supportSite.'/'.($file === 'pictures' ? 'tree' : 'blob').'/'.$this->supportSiteRef.'/'.$path.($line === null ? '' : "#L{$line}");
    }

    public function supportSitePullRequest(int $number): string
    {
        return self::GITHUB.$this->supportSite.'/pull/'.$number;
    }

    public function mainProjectFile(string $path, ?int $line = null): string
    {
        return self::GITHUB.MainProjectIssues::REPOSITORY.'/blob/'.$this->mainProjectCommit.'/'.$path.($line === null ? '' : "#L{$line}");
    }

    /** A main-project file's commits, newest first. */
    public function mainProjectHistory(string $path): string
    {
        return self::GITHUB.MainProjectIssues::REPOSITORY.'/commits/'.$this->mainProjectCommit.'/'.$path;
    }

    public function mainProjectCommit(string $sha): string
    {
        return self::GITHUB.MainProjectIssues::REPOSITORY.'/commit/'.$sha;
    }

    /** An issue or pull request of the main project (GitHub sends either address to the right one). */
    public function mainProjectIssue(int $number): string
    {
        return self::GITHUB.MainProjectIssues::REPOSITORY.'/issues/'.$number;
    }

    public function mainProjectPullRequest(int $number): string
    {
        return self::GITHUB.MainProjectIssues::REPOSITORY.'/pull/'.$number;
    }

    /**
     * Where a Launch Check finding points: "fmm:<path>" and GLOSSARY.md in the main project,
     * "fmm#n" its issue, "pictures" the workflow run (the Picture Check's images), and any
     * other file this repository's.
     */
    public function forFinding(Finding $finding): string
    {
        $file = $finding->file;
        if (str_starts_with($file, 'fmm:')) {
            return $this->mainProjectFile(substr($file, strlen('fmm:')), $finding->line);
        }
        if (preg_match('/\Afmm#(\d+)\z/', $file, $issue) === 1) {
            return $this->mainProjectIssue((int) $issue[1]);
        }
        if ($file === MainGlossary::FILE) {
            return $this->mainProjectFile($file, $finding->line);
        }
        if ($file === 'pictures' && $this->runUrl !== null) {
            return $this->runUrl;
        }

        return $this->supportSiteFile($file, $finding->line);
    }
}
