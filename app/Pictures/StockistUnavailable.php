<?php

namespace App\Pictures;

use RuntimeException;

/**
 * The picture tool couldn't make a Stockist to take Pictures from: the ref couldn't be
 * fetched, the install or seed failed, that version of Stockist can't hold its clock, or its
 * server stopped twice during one take. Nothing is said about any Picture.
 */
final class StockistUnavailable extends RuntimeException {}
