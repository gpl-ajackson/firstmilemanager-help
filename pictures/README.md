# Pictures and their Recipes

> **First Mile Manager:** this folder has no Picture Recipes yet. The picture tool and this guide are copied from Stockist's help centre as they are, and still take Pictures from Stockist alone. The first Recipes (help#3, slice 1) change the tool to take them from a Joint Trentside: Stockist at the version on the demo, with First Mile Manager at the change being made, at the shared Picture Date.

Every picture of a Stockist screen in an Article is a **Picture** taken by the picture tool from its **Picture Recipe**, never by hand ([ADR-0005](../docs/adr/0005-pictures-are-scripted-from-a-fixed-date-trentside-copy.md)). This folder holds both, side by side:

```
pictures/
  warehouses-locations-search.yaml   # the Picture Recipe
  warehouses-locations-search.png    # the Picture the tool took from it
```

The file name is the **Picture Name**: lower case words joined by hyphens, named after the screen and what it shows (`dock-receiving-terminal-flag-a-short-line`). Client-tier Pictures start `client-`. Rename a Picture when its screen changes; nothing outside the help centre links to it.

## Writing a Recipe

A Recipe says, in the screen's own words, how a person gets to the moment the Picture shows. Write it as you would the Article's steps: things are found by what the screen shows (menu items, button words, field labels, headings), never by page code.

```yaml
signs_in_as: Sarah Whitfield
starts_on: Warehouses › Locations
device: desk
steps:
  - enter: { Search: QA-0 }
keep: [Locations, Per page]
outline: New Location
alt: The Locations list searched for QA-0, with New Location at the top right.
articles: [add-a-location]
```

| Field | What to write |
|---|---|
| `signs_in_as` | The Trentside person the Article is written for, by name: Sarah Whitfield (Owner or Admin), Dan Price (Warehouse Operative), Martha Shaw (Hebden Botanicals' Client Account Administrator, for Client-tier Articles). Everyone in the demo is listed when Stockist's `DevDataSeeder` runs. |
| `starts_on` | The screen to start on, by its menu route as the menus name it: `Warehouses › Locations`, or one item such as `Dashboard`. On a handheld the tool opens the menu first. |
| `device` | `desk` (taken 1280 wide) or `handheld` (360 wide, for the Rapid Scanner). A task done on both is shown at the size of the Role Path the Article is for. |
| `steps` | What to do to reach the state, one action per step, in order (below). Leave it out if the starting screen is the Picture. |
| `keep` | What the Picture keeps: `screen` for the whole screen (only for a "find your way here" step), or the words heading the part to keep (`Locations`), or a list of them (`[Locations, Per page]`) to keep everything from the first to the last. The Picture is always the full width of the screen, cropped top and bottom. |
| `outline` | The words of the one thing to press or read, which the tool outlines in dark. Leave it out when nothing needs pointing at. It must be inside what is kept. On a `screen` Picture the tool first scrolls to it if it is below the fold, as a person would (inside the Rapid Scanner too), so the Picture shows the screen at that point. |
| `alt` | What the reader needs from the Picture, in one sentence of 125 characters at most, in `GLOSSARY.md` terms. Never starts "Screenshot", "Image" or "Picture". The step's words must work without the Picture. |
| `articles` | The Article Names of the Articles that use the Picture. |

### Steps

| Step | Does |
|---|---|
| `go_to: Inbound › Shipments` | Follows a menu route, like `starts_on`. |
| `select: New Location` | Presses the button, link, tab or row showing exactly these words. A choice drawn as buttons (such as Sound's **Off**) is selected by its label. |
| `select: { Warehouse: Colwick Distribution Centre }` | Chooses an option in the field with that label. |
| `enter: { Name: Bay 1 }` | Types into the field with that label (one field per step). |
| `scan: PAL-000002` | Scans a code into the Rapid Scanner's scan field (labelled "Scan or type a code"), as a handheld scanner does, and waits for Stockist's answer. Open the Rapid Scanner first (`select: Open the Rapid Scanner`). |
| `wait_for: Location created` | Waits up to 30 seconds for these words to show. Use it after a step whose answer takes a moment. |

Add `in:` to any step to look only in the part of the screen headed by those words, when the same words show twice. A heading in a section's header names the whole section, such as **Sound and Vibration** on My settings:

```yaml
  - select: Edit
    in: Aisle A
```

Match the screen's words exactly (capital letters don't matter, and a required field's `*` is ignored). Reach every state through Stockist's own screens from the Trentside stories, as a person would; if a state is too hard to reach that way, it needs a better Trentside story (a main-project change), not a shortcut.

### Client-tier Pictures

A Picture for a Client-tier Article shows a client's screen as any 3PL's portal would show it, so it never shows Stockist's name or the demo 3PL's (rule 14). Name it `client-` first and sign in as a client's person (Martha Shaw); the tool then does three things no staff Picture gets:

- Before the Recipe starts, the owner of that person's team (Sarah Whitfield) renames the team **Your 3PL** in Team Settings, through Stockist's own screens, so wherever the screen names the 3PL it says "Your 3PL".
- The Picture leaves out Stockist's top bar (the Stockist logo and the team menu): it starts just below it, whatever `keep` says.
- It reads the text the Picture shows (words on the screen, what fields hold, images' alt text) and fails the Recipe if "Stockist" or "Trentside" is in it, any capitals, inside other words too, naming the word and where:

```
BROKEN client-your-account.yaml: text check (a client- Picture never shows Stockist or Trentside): the Picture shows "Trentside" in "priya.desai@trentside.example"
  The screen where it broke: storage/pictures/broken/client-your-account.png
```

Fix it by keeping less (`keep` the part the step is about), or reaching a screen that doesn't name them; the screen where it broke is the Picture as it would have been. Text that is hidden, visually hidden for screen readers or covered by something else doesn't count. If the team can't be renamed, the Recipe is broken at `team name` with the step that failed.

## Taking Pictures

```sh
php artisan pictures:take                                   # every Recipe in pictures/, from Stockist main
php artisan pictures:take warehouses-locations-search       # one or more, by Picture Name
php artisan pictures:take --ref=refs/pull/301/head          # from a main-project pull request
php artisan pictures:take --stockist=../stockist --ref=my-branch   # from a local checkout's branch
php artisan pictures:take --into=/tmp/retaken               # save somewhere else (the Picture Check compares)
```

The tool reads every Recipe first and stops on any mistake, naming the file and field (`INVALID ...`). Then it fetches Stockist at the ref, installs it and seeds the Trentside demo at the **Picture Date** with Stockist's clock held there (`CLOCK_HELD_AT`, stockist#277), keeping that copy for the next run: the first run at a new Stockist commit takes a few minutes, later ones about ten seconds a Recipe. Each Recipe starts from a fresh copy of the seeded demo, signs in, follows its steps in headless Chromium at double density in the light theme, with the browser's clock held at the same moment, and saves its Picture: `TAKEN <Picture Name> <device> <width>x<height> <path>`.

A Recipe that can't be followed is reported with the step that failed, as written, and what went wrong, and its old Picture is left as it was:

```
BROKEN warehouses-locations-new.yaml: step 2 (select: Make a Location): nothing on the screen shows "Make a Location"
  The screen where it broke: storage/pictures/broken/warehouses-locations-new.png
```

It exits 0 when every Picture was taken, 1 when any Recipe is broken or invalid, and 2 when it couldn't make a Stockist to take them from (the message says why).

Same Stockist commit, same seed, same moment: the same Recipe always gives the same image, byte for byte, so a changed Picture means a changed screen. Requests to anywhere but Stockist itself are refused, so no outside service changes a Picture.

### What it needs

- PHP 8.4, Composer, Node 22 and npm, and Playwright with Chromium on `NODE_PATH` (the layout tests use the same).
- Postgres, at the help centre's own connection (`DB_HOST` and so on). The tool makes databases called `<prefix>_<commit>_<moment>`; set `PICTURES_DATABASE_PREFIX` per worktree (default `pictures`).
- Access to the Stockist repository: `PICTURES_STOCKIST_REPOSITORY` (default `https://github.com/gpl-ajackson/stockist.git`, private, so with your git credentials or a token), or `--stockist` with a local checkout.
- It keeps its work in `storage/pictures/` (`PICTURES_WORK_PATH`): the fetched source, one install per commit and pictures of broken screens. Delete it to start afresh.
- Stockist's asset build fetches a font from fonts.bunny.net. Where that can't be reached (a cloud sandbox), set `PICTURES_OFFLINE_FONTS=1` and the build takes the same font family from npm (`@fontsource`) instead. The font is only on Stockist's welcome page, which no Picture shows. CI reaches Bunny Fonts and never sets it.

The Picture Date is in `config/help.php` (`help.pictures.date`): 10:30 UK time on the second Wednesday of January, now 14 January 2026. It moves each January, in one `Help only: yearly Picture Date` change that retakes every Picture.

## The Picture Check

Every Picture must be what its Recipe takes, never touched up by hand. The Picture Check retakes Pictures and compares each with the one in this folder; it lists every Picture a reader would see differ and every Recipe that no longer works.

```sh
php artisan pictures:check                                  # every Picture, against Stockist main
php artisan pictures:check --ref=refs/pull/301/head         # against a main-project pull request
```

```
CHANGED clients-list.png: 2,369 pixels differ, from (812, 344) to (1490, 402)
  before: storage/pictures/check/clients-list.before.png
  after: storage/pictures/check/clients-list.after.png
  difference: storage/pictures/check/clients-list.difference.png
SAME warehouses-locations-search
BROKEN warehouses-locations-new.yaml: step 2 (select: Make a Location): nothing on the screen shows "Make a Location"
```

The images are left in `storage/pictures/check/` (`--artefacts`): the Picture as it is here (before), what its Recipe takes now (after), and the new take faded with every changed pixel marked in magenta and boxed (difference). A broken Recipe's screen is `<Picture Name>.broken.png`. It exits 0 when nothing changed, 1 when a Picture changed or a Recipe broke, 2 when there was no Stockist to take them from. `--markdown=<file>` writes the list for a pull request comment.

**What counts as changed.** Two machines can draw the same screen with slightly different font smoothing, or put a word a fraction of a pixel along. A pixel counts as changed only when the other take has no pixel of about its colour within one pixel, and it isn't a smoothed edge whose colour lies between its neighbours'; a Picture changed when it is a different size or at least 10 pixels changed (about 2×2 at the reader's scale). So a changed number, word, colour or layout is listed, and smoothing isn't.

**Text is drawn the same on every machine.** Unless told otherwise, Chromium takes text hinting and edge smoothing from the machine's own font settings, and GitHub's Ubuntu and a cloud session differ: enough to move every letter a pixel, so every line of text in a Picture differed when CI retook it. The camera starts Chromium with `--font-render-hinting=none --disable-lcd-text`, so a Picture taken in a cloud session matches CI's take. So the processor doesn't matter either: the CI server (`CI_RUNNER`, ARM) draws the same pixels as GitHub's x86-64 machines and a cloud session. Every workflow that takes Pictures (the Picture Check job, the Release Check, the Help Drift Report and the picture tool's own tests) runs on `PICTURE_RUNNER`, a repository variable that defaults to `CI_RUNNER` (and to GitHub's `ubuntu-latest` when neither is set); set it to `ubuntu-latest` to take Pictures on GitHub's machines again.

**A busy machine.** The CI server runs several jobs at once, so a screen can take seconds to answer. The camera waits up to 30 seconds for each step (only a broken Recipe ever waits that long) and up to five minutes for a Recipe. After each step it waits for the next page a link asked for to load and for every Livewire component on the page to start, and it looks again for a button, menu item or field that isn't showing yet, rather than looking once: typing into a Pack Station field before Livewire had started on it sent an empty scan, and a menu item looked for once wasn't there yet, which broke pack-station-packing, pack-station-labels and packaging-items now and then on the CI server (stockist#432). A thing to press is scrolled to only when it can't be pressed where it is (off the screen, under a top bar that stays put, or low in a side column that does), always to the same place for the same page, and then pressed there: Playwright's own retries scrolled a button that wasn't ready yet to the bottom, middle and top of the screen in turn, so load-and-close-out came out scrolled differently from one take to the next. Stockist's server writes its request log to a file, so a long take never stalls it. If Stockist's server stops part way through a take (out of memory, or its port taken), the take is thrown away and had again on a fresh copy; a second stop ends the run as `Stockist unavailable`, with the server's exit code and last words. The server runs with PHP's JIT off (OPcache stays on): with it on, as setup-php leaves it, the ARM CI server crashed part way through a take (exit code 139).

**So are symbols.** Stockist's own fonts lack some characters it shows, such as the ✓ on a Client's account rail, and Chromium draws those from whichever font the machine has: Inter in a cloud session, another font on GitHub's Ubuntu. The camera carries a cut-down DejaVu Sans (`app/Pictures/node/fonts`, symbol blocks only) and puts it straight after each element's own font before taking the Picture, so those characters look the same everywhere while Stockist's fonts still draw every character they have. Emoji still come from the machine; keep them out of Pictures.

**On every pull request** it runs as Launch Check 12, in the Launch Checks workflow's Picture Check job (its own comment on the pull request): the Pictures the pull request adds or changes (by their `.png` or `.yaml`) are retaken from the main project at `main`, or at the paired main-project change, and each one that differs fails the build (`pictures.retaken`), as does each Recipe that broke (`pictures.broken`); the images are the run's `picture-check` artefact. Without a retake the check still fails a Picture with no Recipe, a Recipe that can't be read (alt text starting "Screenshot", say) or that has no Picture, and an Article showing a Picture it can't. Locally:

```sh
php artisan help:launch-checks --main-project=../stockist --take-pictures=changed --pictures-base=main
php artisan help:launch-checks --main-project=../stockist --pictures-only --take-pictures=changed --pictures-base=main   # just the Pictures, as the CI job runs it
```

`--take-pictures=all` retakes every Picture, as the Release Check (against the version being released) and the Help Drift Report (against `main`) do. Pictures are taken from the `--main-project` checkout as it is committed (its `HEAD`).

When it fails, take the Picture again (`php artisan pictures:take <name>`), look at the new one, and if the screen really changed, check the Articles in its Recipe's `articles` still read true.

## The tool's own tests

They take real Pictures from Stockist, so they run apart from the rest of the tests (`phpunit.xml`'s `Pictures` suite, and the `Picture tool` job in CI):

```sh
PICTURES_STOCKIST_REF=main php artisan test --testsuite=Pictures
```

In a cloud session add `PICTURES_DATABASE_PREFIX=pic_w<issue>` and `PICTURES_OFFLINE_FONTS=1`. Recipe checks that need no Stockist are in `tests/Feature/Pictures` and run with everything else, as do the Client-tier rules, which the camera follows through a small fake Stockist (`tests/Support/FakeStockist.php`) in seconds.
