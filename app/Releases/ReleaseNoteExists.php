<?php

namespace App\Releases;

use RuntimeException;

/** A Release Note is already written for that date; a new one never replaces it. */
final class ReleaseNoteExists extends RuntimeException {}
