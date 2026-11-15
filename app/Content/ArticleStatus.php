<?php

namespace App\Content;

/**
 * Draft (never public), Published, or Retired (GLOSSARY.md, Article Status). Only Published
 * Articles are shown, listed and searched. A Retired Article's address redirects to its
 * replacement or shows a notice of what changed (ADR-0001).
 */
enum ArticleStatus: string
{
    case Draft = 'Draft';
    case Published = 'Published';
    case Retired = 'Retired';
}
