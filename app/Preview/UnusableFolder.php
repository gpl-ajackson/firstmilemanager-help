<?php

namespace App\Preview;

use RuntimeException;

/** The folder given for a Help Preview can't take one (it holds files already, or is a file). */
final class UnusableFolder extends RuntimeException {}
