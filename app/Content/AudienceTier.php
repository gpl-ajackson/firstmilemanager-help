<?php

namespace App\Content;

/** Who an Article is written for, never both (GLOSSARY.md, Audience Tier). */
enum AudienceTier: string
{
    case Staff = 'Staff';
    case Client = 'Client';
}
