<?php

namespace App\LaunchChecks;

use App\LaunchChecks\Accessible\AccessibleCheck;
use App\LaunchChecks\ArticleHeader\ArticleHeaderCheck;
use App\LaunchChecks\ArticleTemplate\ArticleTemplateCheck;
use App\LaunchChecks\Fast\FastCheck;
use App\LaunchChecks\Findability\FindabilityCheck;
use App\LaunchChecks\Glossary\GlossaryCheck;
use App\LaunchChecks\HelpOnly\HelpOnlyCheck;
use App\LaunchChecks\Indexing\IndexingCheck;
use App\LaunchChecks\Lengths\LengthsReport;
use App\LaunchChecks\Links\LinksCheck;
use App\LaunchChecks\PersonalDetails\PersonalDetailsCheck;
use App\LaunchChecks\Pictures\PicturesCheck;
use App\LaunchChecks\Privacy\PrivacyCheck;
use App\LaunchChecks\SourceReferences\SourceReferencesCheck;
use App\LaunchChecks\Words\WordsCheck;
use Illuminate\Contracts\Container\Container;

/**
 * The automated Launch Checks runner (spec #30, seam 2): one run over a content folder and a
 * checkout of the main project at a given ref, giving failures (file, line, rule) and the
 * reports that never fail. Support-site CI runs it on every pull request; Help Previews and the
 * Release Check run the same command (help:launch-checks). The Help Drift Report runs checks 2,
 * 4 and 12 on their own (App\Reports\HelpDriftReport) and lists everything they find.
 */
final readonly class LaunchChecks
{
    /**
     * Every automated check, in the order their findings are listed. Later tickets add theirs.
     *
     * @var list<class-string<Check>>
     */
    public const CHECKS = [
        HelpOnlyCheck::class,
        ArticleHeaderCheck::class,
        ArticleTemplateCheck::class,
        SourceReferencesCheck::class,
        LinksCheck::class,
        GlossaryCheck::class,
        WordsCheck::class,
        PersonalDetailsCheck::class,
        AccessibleCheck::class,
        PrivacyCheck::class,
        IndexingCheck::class,
        FindabilityCheck::class,
        FastCheck::class,
        PicturesCheck::class,
        LengthsReport::class,
    ];

    public function __construct(private Container $container) {}

    /** @param  list<class-string<Check>>|null  $only  Just these checks (default: every one). */
    public function run(CheckContext $context, ?array $only = null): Findings
    {
        $findings = new Findings;
        foreach ($only ?? self::CHECKS as $check) {
            $this->container->make($check)->run($context, $findings);
        }

        return $findings;
    }
}
