<?php

namespace App\Records;

/** A reader's answer to "Did this answer your question?". */
enum Answer: string
{
    case Yes = 'Yes';
    case No = 'No';
}
