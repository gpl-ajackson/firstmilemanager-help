/*
 * The picture tool's camera: follows one Picture Recipe through Stockist's own screens in headless
 * Chromium and saves its Picture. Run by App\Pictures\Camera with one JSON argument:
 *
 *   { baseUrl, output, device: {width, height}, moment, email, password,
 *     startsOn: ['Warehouses', 'Locations'], steps: [{said, kind, words, field, in}],
 *     keep: ['screen'] | ['words', ...], outline: 'words' | null,
 *     clientTier: null | { teamName: 'Your 3PL', team: 'Trentside Logistics', owner: {name, email} } }
 *
 * It prints one JSON line: {"taken": true, "width": 2560, "height": 900, "shows": [...]} or
 * {"taken": false, "at": "step 3", "said": "select: Create", "reason": "..."}.
 *
 * A Client-tier Picture (clientTier set) is taken as any 3PL's client would see the screen: first
 * the team's owner renames the team "Your 3PL" in Team Settings, then the Picture leaves out
 * Stockist's top bar (the Stockist logo and the team menu), and "shows" lists the texts the
 * Picture shows, for App\Pictures\ClientTier's text check. Staff Pictures skip all three.
 *
 * Things are found only by what the screen shows (accessible names, labels, headings, visible
 * words), never by page code, so a Recipe reads like the Article's steps. Requests that leave
 * Stockist are refused, so a Picture never depends on another service being reachable.
 */
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

const job = JSON.parse(process.argv[2]);
// How long a step waits for the screen to show something or Stockist to answer. Most steps take
// well under a second; a busy shared CI server (several jobs at once) can take many, so the wait
// is long, and only a broken Recipe ever waits it out.
const STEP_TIMEOUT = 30_000;
// A screen that checks for news every few seconds is never quiet on the network, so waiting for
// that is cut short, whatever the machine.
const IDLE_TIMEOUT = 10_000;
const OUTLINE_COLOUR = '#0E1015'; // Bay ink 950: the outline is dark, never floor yellow.

class Broken extends Error {
    constructor(at, said, reason) {
        super(reason);
        this.at = at;
        this.said = said;
    }
}

const escape = (text) => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
// Exactly these words, ignoring case, surrounding space and a required field's "*".
const exactly = (words) => new RegExp(`^\\s*${escape(words.trim()).replace(/\s+/g, '\\s+')}\\s*\\*?\\s*$`, 'i');

const CLICKABLE = ['button', 'link', 'tab', 'menuitem', 'option', 'checkbox', 'radio', 'switch', 'row', 'cell', 'gridcell'];
const FIELDS = ['textbox', 'searchbox', 'combobox', 'spinbutton', 'listbox'];

/** What a person could see: shown, and not slid off the side of the screen (a closed menu). */
async function visible(locator) {
    const found = [];
    const width = locator.page().viewportSize().width;
    for (const item of await locator.all()) {
        const box = await item.isVisible() ? await item.boundingBox() : null;
        if (box !== null && box.x + box.width > 0 && box.x < width) {
            found.push(item);
        }
    }

    return found;
}

/** The visible things showing these words: by role and name first, then by their own text. */
async function showing(scope, words, roles) {
    for (const role of roles) {
        const found = await visible(scope.getByRole(role, { name: exactly(words) }));
        if (found.length > 0) {
            return found;
        }
    }

    return visible(scope.getByText(exactly(words)));
}

/** The region headed by these words, for `in:` and `keep:`. */
async function regionHeadedBy(page, words) {
    const headings = await visible(page.getByRole('heading', { name: exactly(words) }));
    const found = headings.length > 0 ? headings : await visible(page.getByText(exactly(words)));
    if (found.length === 0) {
        return null;
    }

    // A section's own header holds only its heading and description, never its fields and
    // buttons (Filament's sections on My settings), so the part headed is the whole section.
    return found[0].locator('xpath=ancestor-or-self::*[self::section or self::form or self::fieldset or self::table or self::article or self::aside or self::nav or self::header[not(parent::section)] or self::main or self::dialog or @role="dialog" or @role="region"][1]');
}

async function scopeOf(page, step) {
    if (!step.in) {
        return page;
    }
    const region = await regionHeadedBy(page, step.in);
    if (region === null) {
        throw new Error(`nothing on the screen is headed "${step.in}"`);
    }

    return region;
}

async function one(found, words, what) {
    if (found.length === 0) {
        throw new Error(`nothing on the screen shows ${what ? `${what} ` : ''}"${words}"`);
    }

    return found[0];
}

/**
 * Waits for the screen to finish loading: the network quiet, fonts loaded and images drawn. A
 * step that follows a link can start the next page only after the network looked quiet, which
 * took the page being waited on away mid-wait ("Execution context was destroyed"); then the new
 * page is waited for instead.
 */
async function settle(page) {
    for (let attempt = 1; ; attempt++) {
        try {
            await arrived(page);
            await settleOnce(page);
            await arrived(page);
            return;
        } catch (error) {
            if (attempt >= 3 || !/Execution context was destroyed|navigat/i.test(String(error?.message))) {
                throw error;
            }
            await page.waitForLoadState('load', { timeout: STEP_TIMEOUT }).catch(() => {});
        }
    }
}

/**
 * Waits until the page a step went to has loaded and its screens can be used: the next page
 * after a link once it has come, then every Livewire component on it started, up to
 * STEP_TIMEOUT. A page can show its fields before Livewire has started on it; what was typed
 * then was never seen, so Enter sent an empty scan and its answer never came. That is how
 * pack-station-packing and pack-station-labels broke now and then on a busy CI server, waiting
 * 30 seconds, while a local take was quick enough never to meet it.
 */
async function arrived(page) {
    const ends = Date.now() + STEP_TIMEOUT;
    while (navigating.get(page) && Date.now() < ends) {
        await page.waitForTimeout(50);
    }
    await page.waitForLoadState('load', { timeout: Math.max(1, ends - Date.now()) }).catch(() => {});
    await page.waitForFunction(() => {
        if (document.readyState !== 'complete') {
            return false;
        }
        const components = [...document.querySelectorAll('[wire\\:id]')];

        return components.length === 0 || (window.Livewire !== undefined && components.every((element) => element.__livewire !== undefined));
    }, null, { timeout: Math.max(1, ends - Date.now()), polling: 50 }).catch((error) => {
        // A page swapped mid-wait is waited for again by settle's next attempt.
        if (/Execution context was destroyed|navigat/i.test(String(error?.message))) {
            throw error;
        }
    });
}

async function settleOnce(page) {
    await page.waitForLoadState('networkidle', { timeout: IDLE_TIMEOUT }).catch(() => {});
    await page.evaluate(async () => {
        await document.fonts.ready;
        // Lazy images below the screen never load, so they are not waited for.
        const loading = [...document.images].filter((image) => !image.complete && image.loading !== 'lazy');
        await Promise.race([
            Promise.all(loading.map((image) => new Promise((done) => {
                image.addEventListener('load', done, { once: true });
                image.addEventListener('error', done, { once: true });
            }))),
            new Promise((done) => setTimeout(done, 5000)),
        ]);
    });
}

/**
 * Takes the focus off whatever has it, once anything that focuses itself has: a dialog moves the
 * focus to its first field a moment after it opens, so whether a Picture showed that field's
 * focus ring depended on how quickly it was taken. A screen that puts the focus back once its
 * answer arrives (the Load Scan's field after each scan) can do so after the first blur, so the
 * focus is taken off again once that has had time to happen. Every Picture shows the screen at
 * rest, which for a Scanning Console is its scan field with the cursor.
 */
async function unfocus(page) {
    const frames = () => page.evaluate(() => new Promise((done) => requestAnimationFrame(() => requestAnimationFrame(done))));
    for (let round = 0; round < 2; round++) {
        await frames();
        await page.waitForTimeout(300);
        // A Scanning Console's field keeps the cursor (its scan field, or the answer field of a
        // question it asks): at rest the console is Ready, and taking the cursor away shows it
        // lost ("Tap to scan", a dashed orange border).
        await page.evaluate(() => {
            const focused = document.activeElement;
            if (!(focused?.matches?.('input, textarea') && focused.closest('[data-console]'))) {
                focused?.blur?.();
            }
        });
        await quiet(page);
    }
    await frames();
}

/**
 * Waits until no request the page sent is still out, and none has gone for half a second. Leaving
 * a field sends what was typed in it to Stockist (a form that checks as it goes), and the screen
 * dims and changes while the answer comes back; on a slower machine the blur above left the
 * Picture taken mid-answer.
 */
async function quiet(page) {
    const ends = Date.now() + STEP_TIMEOUT;
    let stillSince = null;
    while (Date.now() < ends) {
        if (inFlight.get(page) > 0) {
            stillSince = null;
        } else if (stillSince === null) {
            stillSince = Date.now();
        } else if (Date.now() - stillSince >= 500) {
            return;
        }
        await page.waitForTimeout(50);
    }
}

/**
 * Draws any character Stockist's own fonts lack (a ✓, an arrow, a warning sign) from the cut-down
 * DejaVu Sans in node/fonts, never from the machine's fonts: GitHub's Ubuntu and a cloud session
 * have different ones, so such a character looked different on each. The tool's font goes straight
 * after each element's first font, so Stockist's fonts still draw every character they have.
 */
async function symbolsFromTheTool(page) {
    const font = (file) => `url(data:font/woff2;base64,${fs.readFileSync(path.join(__dirname, 'fonts', file)).toString('base64')})`;
    await page.evaluate(({ regular, bold }) => {
        const style = document.createElement('style');
        style.textContent = `@font-face { font-family: "Picture Symbols"; src: ${regular}; font-weight: 1 599; }
            @font-face { font-family: "Picture Symbols"; src: ${bold}; font-weight: 600 1000; }`;
        document.head.append(style);
        const generic = /^(serif|sans-serif|monospace|cursive|fantasy|math|emoji|fangsong|system-ui|ui-[a-z-]+)$/;
        const families = [...document.querySelectorAll('*')].map((element) => [element, getComputedStyle(element).fontFamily.split(',').map((family) => family.trim())]);
        for (const [element, [first, ...rest]] of families) {
            if (!generic.test(first)) {
                element.style.fontFamily = [first, '"Picture Symbols"', ...rest].join(', ');
            }
        }
    }, { regular: font('DejaVuSans-Symbols.woff2'), bold: font('DejaVuSans-Bold-Symbols.woff2') });
    await page.evaluate(async () => {
        await document.fonts.ready;
        await new Promise((done) => requestAnimationFrame(() => requestAnimationFrame(done)));
    });
}

/** Follows a menu route, such as Warehouses › Locations, opening the menu on narrow screens. */
async function goTo(page, route) {
    for (const part of route) {
        const menuItems = async () => {
            for (const role of ['link', 'tab', 'menuitem', 'button']) {
                const found = await visible(page.getByRole(role, { name: exactly(part) }));
                if (found.length > 0) {
                    return found;
                }
            }

            return [];
        };
        let found = await menuItems();
        if (found.length === 0) {
            const menu = await visible(page.getByRole('button', { name: /^\s*(open|expand) (the )?(sidebar|navigation|menu)\s*$/i }));
            if (menu.length > 0) {
                await press(menu[0]);
                await settle(page);
            }
            for (const started = Date.now(); found.length === 0 && Date.now() - started <= STEP_TIMEOUT; await page.waitForTimeout(200)) {
                found = await menuItems();
            }
        }
        await press(await one(found, part, 'the menu item'));
        await settle(page);
    }
}

/**
 * The visible things showing these words, looking again for up to STEP_TIMEOUT while there are
 * none: a screen still drawing (a menu group opening, a slide-over coming in, a Livewire answer
 * on its way) shows them a moment later. A button or menu item was looked for once, so on a busy
 * server it could be "nothing on the screen" (packaging-items' menu item). Only a broken Recipe
 * waits it out.
 */
async function showingSoon(page, scope, words, roles) {
    for (const started = Date.now(); ; await page.waitForTimeout(200)) {
        const found = await showing(scope, words, roles);
        if (found.length > 0 || Date.now() - started > STEP_TIMEOUT) {
            return found;
        }
    }
}

/** The field with this label, waiting a moment for a screen still drawing it. */
async function field(scope, label) {
    // A screen can still be drawing its fields once the network is quiet (a Livewire page
    // after a menu click), so look again for a few seconds before calling the step broken.
    for (const started = Date.now(); Date.now() - started <= STEP_TIMEOUT; await new Promise((done) => setTimeout(done, 200))) {
        for (const role of FIELDS) {
            const found = await visible(scope.getByRole(role, { name: exactly(label) }));
            if (found.length > 0) {
                return found[0];
            }
        }
        const labelled = await visible(scope.getByLabel(exactly(label)));
        if (labelled.length > 0) {
            return labelled[0];
        }
    }
    throw new Error(`no field on the screen is labelled "${label}"`);
}

/**
 * The Rapid Scanner's scan field: "Scan or type a code" on the console the scanner became in
 * stockist#408, "Scanned code" on the one before it (a Release Check can still take Pictures
 * from a Stockist that has it).
 */
async function scanField(page) {
    for (const started = Date.now(); Date.now() - started <= STEP_TIMEOUT; await page.waitForTimeout(200)) {
        for (const label of ['Scan or type a code', 'Scanned code']) {
            const found = await visible(page.getByRole('textbox', { name: exactly(label) }));
            if (found.length > 0) {
                return found[0];
            }
        }
    }
    throw new Error('no field on the screen is the Rapid Scanner\'s scan field ("Scan or type a code")');
}

/**
 * Selects what a step names. A choice drawn as buttons, such as My settings' Sound (Off, Quiet,
 * Normal, Loud), is a radio the pointer can't reach (Filament makes it see-through and lets its
 * label take the pointer), so the camera selects its label, as a person does.
 */
async function choose(target) {
    const reachable = await target.evaluate((element) => !(element instanceof HTMLInputElement
        && (element.type === 'radio' || element.type === 'checkbox')
        && getComputedStyle(element).pointerEvents === 'none'
        && element.labels !== null && element.labels.length > 0));
    await press(reachable ? target : (await target.evaluateHandle((element) => element.labels[0])).asElement());
}

/**
 * Presses a thing the way a person does: where it is when it is on the screen, else scrolled to
 * the first place it can be pressed (the bottom, the middle, then the top of the screen, then from
 * the top of the page, for a thing under a top bar that stays put or low in a side column that
 * does), and pressed there. Playwright's own click tries again while the thing isn't ready yet
 * (disabled while the answer to the step before comes back, or under something), and each try
 * scrolls it to the bottom, the middle and then the top of the screen in turn, so a Picture taken
 * on a busy machine showed the page wherever the try that worked had left it
 * (load-and-close-out, stockist#432). Here where it is pressed depends only on the page's layout,
 * and the tries wait there.
 */
async function press(target) {
    await target.scrollIntoViewIfNeeded({ timeout: STEP_TIMEOUT });
    await target.evaluate((element) => {
        const pressable = () => {
            const box = element.getBoundingClientRect();
            const x = box.left + box.width / 2;
            const y = box.top + box.height / 2;
            if (box.width === 0 || x < 0 || y < 0 || x > window.innerWidth || y > window.innerHeight) {
                return false;
            }
            const hit = document.elementFromPoint(x, y);

            return hit !== null && (hit === element || element.contains(hit));
        };
        if (pressable()) {
            return;
        }
        const { scrollX, scrollY } = window;
        const tries = [
            () => element.scrollIntoView({ block: 'end', inline: 'nearest', behavior: 'instant' }),
            () => element.scrollIntoView({ block: 'center', inline: 'nearest', behavior: 'instant' }),
            () => element.scrollIntoView({ block: 'start', inline: 'nearest', behavior: 'instant' }),
            () => { window.scrollTo({ left: 0, top: 0, behavior: 'instant' }); element.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'instant' }); },
            // A side column taller than the screen stays put until the page's end lets it go.
            () => { window.scrollTo({ left: 0, top: document.documentElement.scrollHeight, behavior: 'instant' }); element.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'instant' }); },
        ];
        for (const scroll of tries) {
            scroll();
            if (pressable()) {
                return;
            }
        }
        // Nowhere yet (it is under something for now): back where it was, to wait there.
        window.scrollTo({ left: scrollX, top: scrollY, behavior: 'instant' });
    });
    await target.click({ scroll: 'none' });
}

async function follow(page, step) {
    const scope = await scopeOf(page, step);
    switch (step.kind) {
        case 'go_to':
            await goTo(page, step.route);
            return;
        case 'select':
            if (step.field) {
                const box = await field(scope, step.field);
                if (await box.evaluate((element) => element.tagName === 'SELECT')) {
                    await box.selectOption({ label: step.words });
                } else {
                    await press(box);
                    await settle(page);
                    await press(await one(await showingSoon(page, page, step.words, ['option', 'menuitem']), step.words, 'the choice'));
                }
            } else {
                await choose(await one(await showingSoon(page, scope, step.words, CLICKABLE), step.words, ''));
            }
            break;
        case 'enter':
            await (await field(scope, step.field)).fill(step.words);
            break;
        case 'scan': {
            const box = await scanField(page);
            await box.fill(step.words);
            // A scan goes to Stockist and back; wait for the answer, not just the key press.
            await Promise.all([
                page.waitForResponse((response) => response.request().method() === 'POST', { timeout: STEP_TIMEOUT }),
                box.press('Enter'),
            ]);
            break;
        }
        case 'wait_for':
            await waitForWords(scope, page, step.words);
            break;
        default:
            throw new Error(`the tool can't "${step.kind}"`);
    }
    await settle(page);
}

/**
 * On a Picture that keeps the screen, scrolls to the outlined thing when it is below the fold,
 * as a person would before pressing it: in the page, or inside a panel that scrolls on its own
 * (the Rapid Scanner's slide-over on a handheld). Things already in view stay where they are.
 * drawOutline reports an outline that shows nowhere.
 */
async function scrollToOutline(page) {
    if (!job.outline || !(job.keep.length === 1 && job.keep[0] === 'screen')) {
        return;
    }
    const found = await showing(page, job.outline, [...CLICKABLE, ...FIELDS, 'heading']).catch(() => []);
    if (found.length === 0) {
        return;
    }
    await found[0].scrollIntoViewIfNeeded({ timeout: STEP_TIMEOUT });
    await settle(page);
}

/**
 * Where the Picture starts and ends on the page: the band holding everything kept, starting
 * below the top bar for a Client-tier Picture.
 */
async function band(page) {
    const bar = job.clientTier ? await topBar(page) : { bottom: 0, stays: false };
    if (job.keep.length === 1 && job.keep[0] === 'screen') {
        const scrollY = await page.evaluate(() => window.scrollY);

        return { top: bar.stays ? scrollY + bar.bottom : Math.max(scrollY, bar.bottom), bottom: scrollY + job.device.height };
    }
    let top = Infinity;
    let bottom = -Infinity;
    for (const words of job.keep) {
        const region = await regionHeadedBy(page, words);
        if (region === null) {
            throw new Broken('keep', `keep: ${words}`, `nothing on the screen is headed or shows "${words}"`);
        }
        const box = await region.boundingBox();
        const scrollY = await page.evaluate(() => window.scrollY);
        top = Math.min(top, box.y + scrollY);
        bottom = Math.max(bottom, box.y + scrollY + box.height);
    }
    const height = await page.evaluate(() => document.documentElement.scrollHeight);

    return { top: Math.max(bar.bottom, Math.floor(top) - 16), bottom: Math.min(height, Math.ceil(bottom) + 16) };
}

async function drawOutline(page, kept) {
    if (!job.outline) {
        return;
    }
    let target;
    try {
        const found = await showing(page, job.outline, [...CLICKABLE, ...FIELDS, 'heading']);
        target = await one(found, job.outline, '');
    } catch (error) {
        throw new Broken('outline', `outline: ${job.outline}`, error.message);
    }
    const box = await target.boundingBox();
    const scrollY = await page.evaluate(() => window.scrollY);
    if (box.y + scrollY < kept.top || box.y + scrollY + box.height > kept.bottom) {
        throw new Broken('outline', `outline: ${job.outline}`, `"${job.outline}" is outside what is kept`);
    }
    await page.evaluate(({ box, scrollY, colour }) => {
        const ring = document.createElement('div');
        Object.assign(ring.style, {
            position: 'absolute',
            left: `${box.x + window.scrollX - 5}px`,
            top: `${box.y + scrollY - 5}px`,
            width: `${box.width + 10}px`,
            height: `${box.height + 10}px`,
            border: `3px solid ${colour}`,
            borderRadius: '8px',
            boxSizing: 'border-box',
            pointerEvents: 'none',
            zIndex: '2147483647',
        });
        document.body.appendChild(ring);
    }, { box, scrollY, colour: OUTLINE_COLOUR });
}

async function signIn(page, email) {
    await page.goto(`${job.baseUrl}/login`);
    await (await field(page, 'Email address')).fill(email);
    await (await field(page, 'Password')).fill(job.password);
    await (await one(await showing(page, 'Sign in', ['button']), 'Sign in', 'the button')).click();
    await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: STEP_TIMEOUT });
    await settle(page);
}

async function waitForWords(scope, page, words) {
    for (const started = Date.now(); (await visible(scope.getByText(words))).length === 0;) {
        if (Date.now() - started > STEP_TIMEOUT) {
            throw new Error(`"${words}" did not show within ${STEP_TIMEOUT / 1000} seconds`);
        }
        await page.waitForTimeout(200);
    }
}

/**
 * A Client-tier Picture shows "Your 3PL" wherever the screen names the team: the team's owner
 * renames it through Team Settings, as a 3PL's Admin would, in a browser of their own.
 */
async function renameTeam(browser) {
    const { team, teamName, owner } = job.clientTier;
    const context = await newContext(browser, { width: 1280, height: 800 });
    const page = await newPage(context);
    try {
        await signIn(page, owner.email);
        await (await one(await showing(page, team, ['button']), team, '')).click();
        await settle(page);
        await (await one(await showing(page, 'Team Settings', CLICKABLE), 'Team Settings', '')).click();
        await settle(page);
        const name = await field(page, 'Team Name');
        await name.fill(teamName);
        const form = name.locator('xpath=ancestor::form[1]');
        await (await one(await showing(form, 'Save', ['button']), 'Save', 'the button')).click();
        await waitForWords(page, page, 'Saved');
    } catch (error) {
        const broken = new Broken('team name', `Team Settings › Team Name: ${teamName}, as ${owner.name}`, firstLine(error));
        // The screen where it broke is the owner's, so it is kept before their browser closes.
        if (job.brokenOutput) {
            await page.screenshot({ path: job.brokenOutput, fullPage: true, scale: 'css' }).catch(() => {});
            broken.screenKept = true;
        }
        throw broken;
    } finally {
        await context.close();
    }
}

/**
 * Stockist's top bar, which a Client-tier Picture leaves out: where its bottom edge sits on the
 * page, and whether it stays at the top of the screen as the page scrolls.
 */
async function topBar(page) {
    const bars = await visible(page.getByRole('navigation', { name: 'Topbar', exact: true }));
    if (bars.length === 0) {
        throw new Broken('top bar', 'a client- Picture leaves out the top bar', 'Stockist\'s top bar (the navigation named "Topbar") isn\'t on the screen, so it can\'t be left out');
    }

    return bars[0].evaluate((bar) => {
        // The bar, or whatever holds it at the top of the screen.
        let holder = bar;
        for (let element = bar.parentElement; element && element !== document.body; element = element.parentElement) {
            if (['sticky', 'fixed'].includes(getComputedStyle(element).position)) {
                holder = element;
            }
        }
        holder.setAttribute('data-picture-top-bar', '');
        const { scrollX, scrollY } = window;
        window.scrollTo({ left: 0, top: 0, behavior: 'instant' });
        const bottom = Math.ceil(holder.getBoundingClientRect().bottom);
        window.scrollTo({ left: scrollX, top: scrollY, behavior: 'instant' });

        return { bottom, stays: ['sticky', 'fixed'].includes(getComputedStyle(holder).position) };
    });
}

/**
 * The texts a Picture shows, for a Client-tier Picture's text check: words on the screen, what
 * fields hold, and images' alt text, wherever any part of them is in the kept band and not
 * hidden, visually hidden for screen readers, under something else, or on the top bar.
 */
async function textShown(page, kept) {
    return page.evaluate(({ top, bottom, width }) => {
        const shows = [];
        const add = (text) => {
            const words = (text ?? '').replace(/\s+/g, ' ').trim();
            if (words !== '' && !shows.includes(words)) {
                shows.push(words);
            }
        };
        const counts = (element) => element.closest('[data-picture-top-bar]') === null
            && element.checkVisibility({ checkOpacity: true, checkVisibilityCSS: true });
        // A box as far as it can be seen: cut to every box around it that hides what overflows or
        // is clipped, so text visually hidden for screen readers (a 1 pixel clipped box) is none.
        const seenPart = (element, rect) => {
            let { left, top: rectTop, right, bottom: rectBottom } = rect;
            for (let around = element; around !== null && around !== document.documentElement; around = around.parentElement) {
                const style = getComputedStyle(around);
                if (style.overflowX !== 'visible' || style.overflowY !== 'visible' || style.clip !== 'auto' || style.clipPath !== 'none') {
                    const box = around.getBoundingClientRect();
                    left = Math.max(left, box.left);
                    rectTop = Math.max(rectTop, box.top);
                    right = Math.min(right, box.right);
                    rectBottom = Math.min(rectBottom, box.bottom);
                }
            }

            return new DOMRect(left, rectTop, right - left, rectBottom - rectTop);
        };
        const inPicture = (element, rects) => [...rects].map((rect) => seenPart(element, rect)).some((rect) => {
            if (rect.width < 2 || rect.height < 2) {
                return false;
            }
            const pageTop = rect.top + window.scrollY;
            const pageBottom = rect.bottom + window.scrollY;
            if (pageBottom <= top || pageTop >= bottom || rect.right <= 0 || rect.left >= width) {
                return false;
            }
            const y = rect.top + rect.height / 2;
            if (y < 0 || y >= window.innerHeight) {
                return true;
            }

            return [0.1, 0.3, 0.5, 0.7, 0.9].some((across) => {
                const x = rect.left + rect.width * across;
                if (x < 0 || x >= window.innerWidth) {
                    return false;
                }
                const hit = document.elementFromPoint(x, y);

                return hit === null || element.contains(hit) || hit.contains(element);
            });
        });

        const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
        for (let node = walker.nextNode(); node !== null; node = walker.nextNode()) {
            const element = node.parentElement;
            if (element === null || node.textContent.trim() === '' || ['SCRIPT', 'STYLE', 'NOSCRIPT', 'TEMPLATE'].includes(element.tagName) || !counts(element)) {
                continue;
            }
            const range = document.createRange();
            range.selectNodeContents(node);
            if (inPicture(element, range.getClientRects())) {
                add(node.textContent);
            }
        }

        const unshown = ['hidden', 'password', 'checkbox', 'radio', 'file', 'color', 'range', 'image'];
        for (const field of document.querySelectorAll('input, textarea, select')) {
            if (unshown.includes(field.type) || !counts(field)) {
                continue;
            }
            const value = field.tagName === 'SELECT' ? [...field.selectedOptions].map((option) => option.text).join(' ') : (field.value || field.placeholder);
            if (inPicture(field, field.getClientRects())) {
                add(value);
            }
        }

        for (const image of document.querySelectorAll('img, svg, [role="img"]')) {
            const label = image.tagName === 'IMG' ? image.alt : (image.getAttribute('aria-label') ?? image.querySelector(':scope > title')?.textContent);
            if (label && counts(image) && inPicture(image, image.getClientRects())) {
                add(label);
            }
        }

        return shows;
    }, { ...kept, width: job.device.width });
}

const firstLine = (error) => String(error.message ?? error).split('\n')[0];

async function followRecipe(page) {
    try {
        await signIn(page, job.email);
    } catch (error) {
        throw new Broken('signs_in_as', `signs_in_as: ${job.signsInAs}`, `could not sign in: ${firstLine(error)}`);
    }

    try {
        await goTo(page, job.startsOn);
    } catch (error) {
        throw new Broken('starts_on', `starts_on: ${job.startsOn.join(' › ')}`, firstLine(error));
    }

    for (const [index, step] of job.steps.entries()) {
        try {
            await follow(page, step);
        } catch (error) {
            throw new Broken(`step ${index + 1}`, step.said, firstLine(error));
        }
    }

    await page.mouse.move(0, 0);
    await settle(page);
    await unfocus(page);
    await symbolsFromTheTool(page);
    await scrollToOutline(page);
    const kept = await band(page);
    await drawOutline(page, kept);
    const image = await page.screenshot({
        path: job.output,
        fullPage: true,
        clip: { x: 0, y: kept.top, width: job.device.width, height: kept.bottom - kept.top },
        animations: 'disabled',
        caret: 'hide',
        scale: 'device',
    });
    const taken = { width: image.readUInt32BE(16), height: image.readUInt32BE(20) };

    return job.clientTier ? { ...taken, shows: await textShown(page, kept) } : taken;
}

/** A browser at the device's size and density, light theme, UK, refusing every other site. */
async function newContext(browser, device) {
    const context = await browser.newContext({
        viewport: { width: device.width, height: device.height },
        deviceScaleFactor: 2,
        colorScheme: 'light',
        reducedMotion: 'reduce',
        locale: 'en-GB',
        timezoneId: 'Europe/London',
    });
    context.setDefaultTimeout(STEP_TIMEOUT);
    const origin = new URL(job.baseUrl).origin;
    await context.route('**/*', (route) => (new URL(route.request().url()).origin === origin ? route.continue() : route.abort()));

    return context;
}

/** How many of each page's fetch and XHR requests are still out (quiet() reads it). */
const inFlight = new WeakMap();
/** Whether each page has asked for its next page and not yet loaded it (arrived() reads it). */
const navigating = new WeakMap();

async function newPage(context) {
    const page = await context.newPage();
    inFlight.set(page, 0);
    navigating.set(page, false);
    page.on('request', (request) => {
        if (request.isNavigationRequest() && request.frame() === page.mainFrame()) {
            navigating.set(page, true);
        }
    });
    page.on('requestfailed', (request) => request.isNavigationRequest() && request.frame() === page.mainFrame() && navigating.set(page, false));
    page.on('load', () => navigating.set(page, false));
    const counted = (request) => ['fetch', 'xhr'].includes(request.resourceType());
    page.on('request', (request) => counted(request) && inFlight.set(page, inFlight.get(page) + 1));
    for (const ended of ['requestfinished', 'requestfailed']) {
        page.on(ended, (request) => counted(request) && inFlight.set(page, Math.max(0, inFlight.get(page) - 1)));
    }
    // The browser's clock is held at the Picture Date too, so screens that count from the
    // browser's "now" agree with Stockist's.
    await page.clock.setFixedTime(new Date(job.moment));
    await page.addInitScript(() => {
        const style = () => {
            const sheet = document.createElement('style');
            sheet.textContent = '*, *::before, *::after { transition: none !important; animation: none !important; caret-color: transparent !important; }';
            document.head.appendChild(sheet);
        };
        document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', style) : style();
    });

    return page;
}

async function main() {
    // Text is drawn the same way on every machine: no hinting and plain grey edges. Chromium
    // otherwise takes both from the machine's font settings (GitHub's Ubuntu hints slightly, a
    // cloud session doesn't), which moves every glyph a pixel or so and makes every line of text
    // in a Picture differ.
    const browser = await chromium.launch({ args: ['--font-render-hinting=none', '--disable-lcd-text'] });
    try {
        const context = await newContext(browser, job.device);
        const page = await newPage(context);

        try {
            if (job.clientTier) {
                await renameTeam(browser);
            }

            return { taken: true, ...(await followRecipe(page)) };
        } catch (error) {
            if (!(error instanceof Broken)) {
                throw error;
            }
            // A Recipe that breaks leaves a picture of the screen where it broke, for its writer.
            if (job.brokenOutput && !error.screenKept) {
                await page.screenshot({ path: job.brokenOutput, fullPage: true, scale: 'css' }).catch(() => {});
            }

            return { taken: false, at: error.at, said: error.said, reason: error.message };
        }
    } finally {
        await browser.close();
    }
}

main().then(
    (result) => process.stdout.write(JSON.stringify(result) + '\n'),
    (error) => {
        process.stderr.write(`${error.stack ?? error}\n`);
        process.exit(1);
    },
);
