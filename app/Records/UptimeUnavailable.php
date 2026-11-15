<?php

namespace App\Records;

use RuntimeException;

/** The uptime monitor isn't set up on this server, couldn't be reached, or gave no availability. */
final class UptimeUnavailable extends RuntimeException {}
