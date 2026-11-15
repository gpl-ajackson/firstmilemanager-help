<?php

namespace App\Releases\Check;

/**
 * A released change's help status (ADR-0003): the release waits until each change's Help
 * Proposal is merged or it says "No help changes".
 */
enum HelpStatus: string
{
    /** Its Help Proposal merged, it says "No help changes", or it is exempt (Dependabot). */
    case Complete = 'OK';

    /** Its Help Proposal is still open. */
    case Waiting = 'WAITING';

    /** No Help Proposal or reason, or the one linked was closed without merging or doesn't exist. */
    case Missing = 'MISSING';

    /** GitHub couldn't be asked, so its help status is unknown. */
    case Unread = 'UNREAD';

    public function isComplete(): bool
    {
        return $this === self::Complete;
    }
}
