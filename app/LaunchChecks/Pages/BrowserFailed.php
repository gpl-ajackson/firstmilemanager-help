<?php

namespace App\LaunchChecks\Pages;

use RuntimeException;

/** Headless Chromium couldn't run the page checks (not installed, or it stopped part way). */
final class BrowserFailed extends RuntimeException {}
