<?php

namespace App\Records;

/** How a Search ended (GLOSSARY.md, "Search"), in the glossary's words. */
enum SearchOutcome: string
{
    /** Nothing was shown, not even a Glossary Entry. */
    case NoResults = 'No results';

    /** A result was opened from it. */
    case Opened = 'Opened';

    /** Results were shown and none was opened. */
    case NotOpened = 'Not opened';
}
