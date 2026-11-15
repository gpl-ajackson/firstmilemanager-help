<?php

namespace App\Releases\Check;

use RuntimeException;

/** The Release Check can't start: no checkout of the main project, or no last FMM Release in it. */
final class InvalidRelease extends RuntimeException {}
