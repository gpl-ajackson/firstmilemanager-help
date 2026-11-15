<?php

namespace App\Reports;

use RuntimeException;

/** GitHub couldn't be asked, or didn't answer, so a report can't say what it would have read. */
final class GitHubUnreadable extends RuntimeException {}
