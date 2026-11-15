<?php

namespace App\Content;

/** The kind of Article, which sets the template its writer follows (GLOSSARY.md, Article Type). */
enum ArticleType: string
{
    case GettingStarted = 'Getting-started';
    case HowTo = 'How-to';
    case Concept = 'Concept';
    case Reference = 'Reference';
    case Troubleshooting = 'Troubleshooting';
    case Faq = 'FAQ';
}
