<?php

namespace App\LaunchChecks;

/**
 * One automated Launch Check (spec #30, "Automated Launch Checks"). Later checks implement this
 * and join LaunchChecks::CHECKS, so CI, Help Previews, the Release Check and the Help Drift
 * Report keep running one command.
 */
interface Check
{
    public function run(CheckContext $context, Findings $findings): void;
}
