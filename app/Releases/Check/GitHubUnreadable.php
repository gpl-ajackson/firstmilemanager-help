<?php

namespace App\Releases\Check;

use RuntimeException;

/** GitHub couldn't be asked, or didn't answer, so a help status is unknown. */
final class GitHubUnreadable extends RuntimeException {}
