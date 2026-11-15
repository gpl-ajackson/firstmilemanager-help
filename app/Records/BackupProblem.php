<?php

namespace App\Records;

use RuntimeException;

/** A backup couldn't be made or restored: no backup store set up, no such backup, a damaged one, or a database that already holds records. */
final class BackupProblem extends RuntimeException {}
