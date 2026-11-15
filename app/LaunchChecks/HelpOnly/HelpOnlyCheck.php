<?php

namespace App\LaunchChecks\HelpOnly;

use App\LaunchChecks\Check;
use App\LaunchChecks\CheckContext;
use App\LaunchChecks\Findings;

/**
 * The Help only check: a support-site pull request links the main-project pull request it
 * carries help for, or says why it has none with "Help only: <reason>" (plan S7, ADR-0003).
 * It needs a pull request's description, so it doesn't run without one (locally, on main).
 */
final class HelpOnlyCheck implements Check
{
    public const WHERE = 'pull request description';

    public function run(CheckContext $context, Findings $findings): void
    {
        if ($context->pullRequestDescription === null) {
            $findings->notRun('Help only check: no pull request description was given');

            return;
        }

        $description = new PullRequestDescription($context->pullRequestDescription);
        if ($description->pairedPullRequest() === null && $description->helpOnlyReason() === null) {
            $findings->fail('help-only.description', self::WHERE, null,
                'links no main-project pull request (https://github.com/'.PullRequestDescription::MAIN_PROJECT.'/pull/<number>) and doesn\'t say "Help only: <reason>"');
        }
    }
}
