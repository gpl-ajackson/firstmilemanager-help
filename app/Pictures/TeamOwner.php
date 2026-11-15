<?php

namespace App\Pictures;

/**
 * The owner of the team a Client-tier Recipe's person belongs to, who renames the team "Your 3PL"
 * in Team Settings before the Picture is taken (ClientTier).
 */
final readonly class TeamOwner
{
    /**
     * @param  string  $team  the team's name before it is renamed, as its tenant menu shows it
     */
    public function __construct(
        public string $team,
        public string $name,
        public string $email,
    ) {}
}
