<?php

namespace App\IssueDesk;

use RuntimeException;

/** GitHub couldn't be reached, or refused what was asked (a bad key, no access to issues). */
final class IssueDeskUnavailable extends RuntimeException {}
