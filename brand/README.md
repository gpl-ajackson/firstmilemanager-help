# Stockist WMS brand (Bay)

AJ chose the **Bay** brand on 23 October 2026. The full brand book is the Stockist WMS design system (https://claude.ai/artifact/93adEmbT2XDEMwk1q9qwd6). This folder holds what the support site needs to use it. The marketing site (`stockist-marketing-site`) and the staff app (`stockist`) use the same files.

## Where it applies on this site

- **Stockist's own pages:** the public help centre for 3PL staff, the request desk, service status, release notes and the subscription pages carry the Stockist WMS brand.
- **Branded help centres:** each 3PL's help centre for its clients carries **only that 3PL's** name, logo and colours (plan rule 14). It never loads `tokens.css`, the Stockist logo, the Stockist favicon or the Stockist fonts, and it has no "powered by" line. Give it a neutral base theme that the 3PL's colours and logo go on top of.

## Files

- `tokens.css`: every colour, spacing, radius and font as a CSS custom property (`--sk-page`, `--sk-ink`, `--sk-floor`, `--sk-success` and so on). It is light by default and switches to dark with `prefers-color-scheme`, or you can force a theme with `data-theme="light"` or `data-theme="dark"` on `<html>`. It also declares the fonts from `/fonts/`, so copy `fonts/` to `public/fonts/`.
- `tokens.json`: the same tokens as data, with a usage note on each.
- `logos/`: `stockist-wms-lockup-light.svg` is the default header logo, and `-dark.svg` is for the dark theme. There are also the mark on its own, one-colour versions and a stacked lock-up.
- `icons/`: favicons, app icons and `og-default.png`, the 1200×630 sharing image.
- `fonts/`: Barlow Condensed (headings), Atkinson Hyperlegible Next (text) and Atkinson Hyperlegible Mono (codes), with their SIL Open Font Licences. Self-host them; don't load Google Fonts.

## Rules to keep

- **Floor yellow** (`--sk-floor`) is only ever a fill, with `--sk-on-floor` text on it. Never use yellow for text or icons on a light background, and never use it for a status.
- **Warning is orange** (`--sk-warning`). Every status shows a word as well as a colour.
- **Headings:** article and page headings use Barlow Condensed. Section headings on landing pages are in capitals, and article titles are in sentence case.
- **Body text** is Atkinson Hyperlegible Next at 16px. Location codes, order numbers and SKUs use the mono face.
- **Links** are `--sk-link` and always underlined. Keyboard focus uses `--sk-focus-ring`.
- **Corners** are square-shouldered: 3px on badges, 6px on buttons and inputs, 10px on cards.
- **Voice:** plain UK English in 3PL words from the product's `GLOSSARY.md`. The help centre uses the office register: full sentences, numbered steps, and the fix in every error ("Location A1-03-B is full. Choose another location or split the pallet."). Never use "seamless", "powerful" or exclamation marks.
- **Screenshots** show the Trentside Logistics demo only. Screenshots of client-facing screens show Trentside's brand, not Stockist's.
