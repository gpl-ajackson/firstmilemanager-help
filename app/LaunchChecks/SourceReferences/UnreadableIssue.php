<?php

namespace App\LaunchChecks\SourceReferences;

use RuntimeException;

/** GitHub couldn't say whether a cited issue exists: no answer, a refused key, a limit reached. */
final class UnreadableIssue extends RuntimeException {}
