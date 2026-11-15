<?php

namespace App\Reports;

use RuntimeException;

/** The main project's checkout has no history to read (not a git checkout, or a shallow one). */
final class UnreadableHistory extends RuntimeException {}
