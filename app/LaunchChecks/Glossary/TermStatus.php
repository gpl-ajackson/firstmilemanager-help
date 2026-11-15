<?php

namespace App\LaunchChecks\Glossary;

/**
 * Whether a GLOSSARY.md term is published in the help centre (spec #30, Content folder): it
 * has a Glossary Entry; it is Internal, never published because readers never meet it; or it
 * is Not built yet, published the day its feature ships.
 */
enum TermStatus: string
{
    case Published = 'Published';
    case Internal = 'Internal';
    case NotBuiltYet = 'Not built yet';
}
