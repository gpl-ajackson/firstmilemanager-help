// The Launch Checks' page checks in headless Chromium (App\LaunchChecks\Pages\Browser runs it):
// Launch Checks 7 (accessible and fits), 8 (privacy) and 11 (fast on the floor), over every
// published page of the content under test.
//
//   node pages.cjs '{"mode": "scan" | "privacy" | "speed", "origin": "http://help.test", "bridged": true, ...}'
//
// Bridged (the content under test): every request the page makes is written to stdout as one JSON
// line, {"request": {...}}, and its answer is read back from stdin as one JSON line, so the help
// centre's own app answers it from the content loaded into its database for the check. Files
// that exist in public/ (favicons) are served from disk. Requests to any other origin are
// refused, so no outside service is ever asked for anything.
//
// Not bridged (the real address, for check 11 before launch): requests go to the network, with
// the job's extra headers (Cloudflare Access's service token).
//
// Modes:
//   scan:    each page in light and dark at each width: an axe scan (job.tags; in the other
//            themes job.colourRules, since only colours change), sideways
//            scrolling, controls smaller than job.control px, search boxes shorter than
//            job.searchBox px; and the cookies and storage the page leaves.
//   privacy: a reader searches job.words from the home page and opens the first result, answers
//            No with a reason on job.vote, and opens "Still stuck?" (job.stillStuck).
//   speed:   each page (one per template, or with job.weigh each template's heaviest) timed from
//            a cold start on the job.profile network (simulated when bridged, Chromium's own
//            throttling at the real address).
//
// Ends with {"done": {...}}; anything that stops the run goes to stderr with exit code 1.
const fs = require('node:fs');
const path = require('node:path');
const readline = require('node:readline');
const { chromium } = require('playwright');

const types = { '.css': 'text/css', '.js': 'text/javascript', '.woff2': 'font/woff2', '.svg': 'image/svg+xml', '.png': 'image/png', '.ico': 'image/x-icon', '.webp': 'image/webp' };
const WORKERS = 4;

const job = JSON.parse(process.argv[2]);
const origin = job.origin.replace(/\/$/, '');

// ---------- the bridge to the app under test ----------
const answers = new Map();
let nextId = 1;
if (job.bridged) {
    readline.createInterface({ input: process.stdin }).on('line', (line) => {
        const answer = JSON.parse(line);
        answers.get(answer.id)(answer);
        answers.delete(answer.id);
    });
}

function ask(request) {
    const id = nextId++;
    return new Promise((resolve) => {
        answers.set(id, resolve);
        process.stdout.write(JSON.stringify({ request: { id, ...request } }) + '\n');
    });
}

const firstLine = (error) => String(error && error.message || error).split('\n')[0];
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, Math.max(0, ms)));

/**
 * A browser context whose requests the app under test answers (bridged) or the network does.
 * `network` is the slow 4G profile to simulate, or null. Each context counts what it loaded.
 */
async function newContext(browser, options = {}, network = null) {
    const context = await browser.newContext({
        userAgent: job.userAgent,
        extraHTTPHeaders: job.headers || {},
        viewport: { width: 1280, height: 800 },
        ...options,
    });
    context.loaded = { bytes: 0, requests: 0, setCookies: [] };
    if (!job.bridged) {
        return context;
    }
    // One simulated link per context: each answer arrives a round trip after the server
    // answered, and answers share the download speed, one after another.
    let linkFreeAt = 0;
    await context.route('**/*', async (route) => {
        const request = route.request();
        const url = new URL(request.url());
        if (url.origin !== origin) {
            return route.abort('blockedbyclient');
        }
        const body = request.postDataBuffer();
        const file = path.join(job.public, path.normalize(url.pathname));
        let answer;
        if (request.method() === 'GET' && file.startsWith(job.public + path.sep) && fs.existsSync(file) && fs.statSync(file).isFile()) {
            answer = { status: 200, headers: { 'Content-Type': types[path.extname(file)] || 'application/octet-stream' }, body: fs.readFileSync(file) };
        } else {
            const asked = await ask({
                method: request.method(),
                url: url.href,
                headers: await request.allHeaders(),
                body: body ? body.toString('base64') : null,
            });
            answer = { status: asked.status, headers: asked.headers, body: Buffer.from(asked.body, 'base64') };
        }
        context.loaded.bytes += answer.body.length;
        context.loaded.requests += 1;
        for (const [name, value] of Object.entries(answer.headers)) {
            if (name.toLowerCase() === 'set-cookie') {
                context.loaded.setCookies.push(value.split(';')[0].split('=')[0].trim());
            }
        }
        if (network) {
            const sent = Date.now() + ((body ? body.length : 0) / network.upload_bytes_per_second) * 1000;
            const start = Math.max(sent + network.latency_ms, linkFreeAt);
            linkFreeAt = start + (answer.body.length / network.download_bytes_per_second) * 1000;
            await sleep(linkFreeAt - Date.now());
        }
        return route.fulfill({ status: answer.status, headers: answer.headers, body: answer.body });
    });

    return context;
}

/** Runs `each` over `items`, a few at a time, keeping the items' order in the results. */
async function eachAtOnce(items, each) {
    const results = new Array(items.length);
    let next = 0;
    async function worker() {
        while (next < items.length) {
            const index = next++;
            results[index] = await each(items[index], index);
        }
    }
    await Promise.all(Array.from({ length: Math.min(WORKERS, items.length) }, worker));
    return results;
}

/** What the page keeps on the reader's device: cookies, local and session storage, IndexedDB. */
async function kept(context, page) {
    const storage = await page.evaluate(async () => ({
        local: localStorage.length,
        session: sessionStorage.length,
        indexedDb: indexedDB.databases ? (await indexedDB.databases()).map((database) => database.name) : [],
    })).catch(() => ({ local: 0, session: 0, indexedDb: [] }));
    return {
        cookies: [...new Set([...(await context.cookies()).map((cookie) => cookie.name), ...context.loaded.setCookies])],
        local: storage.local,
        session: storage.session,
        indexedDb: storage.indexedDb,
    };
}

// ---------- scan: accessible and fits, and what each page keeps ----------

/** Measured inside the page: sideways scrolling, small controls and short search boxes. */
function measure(limits) {
    const describe = (element) => {
        const name = element.tagName.toLowerCase() + [...element.classList].map((name) => '.' + name).join('');
        const parent = element.parentElement && element.parentElement !== document.body ? element.parentElement : null;
        return parent ? parent.tagName.toLowerCase() + [...parent.classList].map((name) => '.' + name).join('') + ' > ' + name : name;
    };
    const words = (element) => (element.getAttribute('aria-label') || element.innerText || element.value || element.getAttribute('placeholder') || '').replace(/\s+/g, ' ').trim().slice(0, 60);
    const shown = (element) => {
        const style = getComputedStyle(element);
        const box = element.getBoundingClientRect();
        // Hidden, visually hidden (1px and clipped), or off the page until focused (the skip link).
        return style.display !== 'none' && style.visibility !== 'hidden' && box.width > 1 && box.height > 1
            && box.right + scrollX > 0 && box.bottom + scrollY > 0;
    };
    const blockOf = (element) => {
        for (let parent = element.parentElement; parent; parent = parent.parentElement) {
            if (!getComputedStyle(parent).display.startsWith('inline')) {
                return parent;
            }
        }
        return document.body;
    };
    // A link in running text (a sentence or list item with other words) is sized by its line,
    // as WCAG 2.5.8's inline exception allows; any other control must be big enough for gloves.
    const inText = (link) => blockOf(link).innerText.replace(/\s+/g, ' ').trim().length > link.innerText.replace(/\s+/g, ' ').trim().length;

    const root = document.documentElement;
    const overflowing = [];
    if (root.scrollWidth > root.clientWidth) {
        for (const element of document.body.querySelectorAll('*')) {
            const box = element.getBoundingClientRect();
            if (box.right + scrollX > root.clientWidth + 1 && shown(element)
                && ![...element.children].some((child) => child.getBoundingClientRect().right + scrollX > root.clientWidth + 1)) {
                overflowing.push(describe(element));
            }
        }
    }

    const small = [];
    for (const element of document.querySelectorAll('a[href], button, input:not([type=hidden]), select, textarea, summary, [role=button]')) {
        if (!shown(element) && !['radio', 'checkbox'].includes(element.type)) {
            continue;
        }
        if (element.matches('a') && inText(element)) {
            continue;
        }
        // A radio or checkbox is hit through its label.
        const target = ['radio', 'checkbox'].includes(element.type) ? (element.closest('label') || (element.id && document.querySelector('label[for="' + element.id + '"]')) || element) : element;
        if (!shown(target)) {
            continue;
        }
        const box = target.getBoundingClientRect();
        if (box.width < limits.control - 0.5 || box.height < limits.control - 0.5) {
            small.push({ what: describe(element) + (words(target) ? ' "' + words(target) + '"' : ''), width: Math.round(box.width), height: Math.round(box.height) });
        }
    }

    const searchBoxes = [];
    for (const box of document.querySelectorAll('input[type=search]')) {
        const height = box.getBoundingClientRect().height;
        if (shown(box) && height < limits.searchBox - 0.5) {
            searchBoxes.push({ what: describe(box), height: Math.round(height) });
        }
    }

    return { scrollWidth: root.scrollWidth, clientWidth: root.clientWidth, overflowing: [...new Set(overflowing)].slice(0, 3), small, searchBoxes };
}

async function scan(browser) {
    const axe = fs.readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');
    const pages = await eachAtOnce(job.pages, async (address) => {
        const context = await newContext(browser, { viewport: { width: job.widths[0], height: 800 }, colorScheme: job.schemes[0] });
        await context.addInitScript({ content: axe });
        try {
            const page = await context.newPage();
            let response;
            try {
                response = await page.goto(origin + address, { waitUntil: 'load', timeout: 60000 });
            } catch (error) {
                return { address, status: 0, error: firstLine(error), url: '', bytes: 0, views: [], kept: { cookies: [], local: 0, session: 0, indexedDb: [] } };
            }
            const bytes = context.loaded.bytes;
            const views = [];
            for (const scheme of job.schemes) {
                await page.emulateMedia({ colorScheme: scheme });
                for (const width of job.widths) {
                    await page.setViewportSize({ width, height: 800 });
                    const fits = await page.evaluate(measure, { control: job.control, searchBox: job.searchBox });
                    // Only colours change with the theme, so the other themes are scanned for colour alone.
                    const only = scheme === job.schemes[0] ? { type: 'tag', values: job.tags } : { type: 'rule', values: job.colourRules };
                    const violations = await page.evaluate(async (runOnly) => (await window.axe.run(document, {
                        runOnly,
                        resultTypes: ['violations'],
                    })).violations.map((violation) => ({
                        id: violation.id,
                        help: violation.help,
                        count: violation.nodes.length,
                        targets: violation.nodes.slice(0, 3).map((node) => node.target.join(' ')),
                    })), only);
                    views.push({ scheme, width, ...fits, violations });
                }
            }
            return { address, status: response ? response.status() : 0, url: page.url().replace(origin, ''), bytes, views, kept: await kept(context, page) };
        } finally {
            await context.close();
        }
    });
    return { pages };
}

// ---------- privacy: a reader searches, votes and asks for help ----------

async function privacy(browser) {
    const context = await newContext(browser, { viewport: { width: 390, height: 800 } });
    const page = await context.newPage();
    const did = [];
    try {
        await page.goto(origin + '/', { waitUntil: 'load' });
        await page.getByRole('searchbox').first().fill(job.words);
        await Promise.all([page.waitForURL(/\/search\?/), page.getByRole('searchbox').first().press('Enter')]);
        await page.waitForLoadState('load');
        did.push('searched');
        const result = page.locator('.results .result-title a').first();
        if (await result.count()) {
            await Promise.all([page.waitForURL((url) => !url.pathname.startsWith('/search')), result.click()]);
            await page.waitForLoadState('load');
            did.push('opened a result');
        }

        if (job.vote) {
            await page.goto(origin + job.vote, { waitUntil: 'load' });
            await page.getByRole('button', { name: 'No', exact: true }).click();
            await page.getByRole('radio').first().check({ timeout: 10000 });
            await page.getByRole('button', { name: 'Send', exact: true }).click();
            await page.getByText('Thanks for telling us', { exact: true }).first().waitFor({ timeout: 10000 });
            did.push('voted');
        }
        if (job.stillStuck) {
            await page.goto(origin + job.stillStuck.article, { waitUntil: 'load' });
            // The link sends the reader on to an email; the browser asks for it as a reader's would.
            await page.evaluate((address) => fetch(address, { redirect: 'manual' }), job.stillStuck.address);
            did.push('asked "Still stuck?"');
        }
        return { did, kept: await kept(context, page) };
    } finally {
        await context.close();
    }
}

// ---------- speed: each template on a slow 4G connection ----------

/**
 * Opens a page from a cold start and says when it was usable: the later of DOMContentLoaded (the
 * page drawn with its styles, its script ready, so the search box and buttons work) and the
 * largest contentful paint (the main content, or the picture that leads it, on screen).
 */
async function load(browser, address, network) {
    const context = await newContext(browser, { viewport: { width: 390, height: 800 } }, job.bridged ? network : null);
    try {
        const page = await context.newPage();
        if (!job.bridged) {
            page.on('requestfinished', async (request) => {
                const sizes = await request.sizes().catch(() => null);
                context.loaded.requests += 1;
                context.loaded.bytes += sizes ? sizes.responseBodySize : 0;
            });
            if (network) {
                const cdp = await context.newCDPSession(page);
                await cdp.send('Network.enable');
                await cdp.send('Network.emulateNetworkConditions', {
                    offline: false,
                    latency: network.latency_ms,
                    downloadThroughput: network.download_bytes_per_second,
                    uploadThroughput: network.upload_bytes_per_second,
                });
            }
        }
        let response;
        try {
            response = await page.goto(origin + address, { waitUntil: 'load', timeout: 60000 });
        } catch (error) {
            return { address, status: 0, error: firstLine(error), url: page.url(), ms: 0, bytes: context.loaded.bytes, requests: context.loaded.requests };
        }
        const usable = await page.evaluate(() => new Promise((resolve) => {
            const navigation = performance.getEntriesByType('navigation')[0];
            const ready = navigation ? navigation.domContentLoadedEventEnd : performance.now();
            let painted = 0;
            new PerformanceObserver((entries) => {
                for (const entry of entries.getEntries()) {
                    painted = Math.max(painted, entry.startTime);
                }
            }).observe({ type: 'largest-contentful-paint', buffered: true });
            setTimeout(() => resolve(Math.max(ready, painted)), 50);
        }));
        return { address, status: response ? response.status() : 0, url: page.url(), ms: Math.round(usable), bytes: context.loaded.bytes, requests: context.loaded.requests };
    } finally {
        await context.close();
    }
}

async function speed(browser) {
    // Each template's heaviest page, by what it loads (unless the job already chose them).
    let pages = job.pages;
    if (job.weigh) {
        const weighed = await eachAtOnce(job.pages, (page) => load(browser, page.address, null));
        const heaviest = new Map();
        job.pages.forEach((page, index) => {
            const best = heaviest.get(page.template);
            if (!best || weighed[index].bytes > best.bytes) {
                heaviest.set(page.template, { ...page, bytes: weighed[index].bytes });
            }
        });
        pages = [...heaviest.values()];
    }
    // Simulated, each page has a link of its own, so templates are timed side by side; over a
    // real network they are timed one at a time, so pages don't share it.
    const timed = job.bridged
        ? await eachAtOnce(pages, (page) => load(browser, page.address, job.profile).then((timing) => ({ template: page.template, ...timing })))
        : [];
    if (!job.bridged) {
        for (const page of pages) {
            timed.push({ template: page.template, ...(await load(browser, page.address, job.profile)) });
        }
    }
    return { timed };
}

(async () => {
    const browser = await chromium.launch();
    let exitCode = 0;
    try {
        const run = { scan, privacy, speed }[job.mode];
        if (!run) {
            throw new Error('Unknown mode ' + job.mode);
        }
        process.stdout.write(JSON.stringify({ done: await run(browser) }) + '\n');
    } catch (error) {
        process.stderr.write(String(error && error.stack || error));
        exitCode = 1;
    } finally {
        await browser.close();
        process.exit(exitCode);
    }
})();
