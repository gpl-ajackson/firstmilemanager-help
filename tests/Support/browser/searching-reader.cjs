// A reader in headless Chromium with script on and the page's clock held (Playwright's
// page.clock), typing into the search box, whose every request goes through the Laravel app
// under test (SearchingReader.php runs it). Files that exist in public/ are served from disk;
// every other request is written to stdout as one JSON line, {"request": {...}}, and its answer
// is read back from stdin as one JSON line, so the test's own app (and its database
// transaction) answers it.
//
//   node searching-reader.cjs '{"public": "/path/public", "userAgent": "...", "open": "/search", "steps": [...]}'
//
// Steps, in order (after each, it waits until no request is open):
//   ["type", "<text>"]       types into the page's search box, one key at a time
//   ["press", "<key>"]       presses a key in the search box ("Enter", "Backspace", ...)
//   ["idle", <ms>]           lets the page's clock run on, as if the reader stopped for that long
//   ["click", "<button>"]    clicks a button by its name
//   ["open", "<link>"]       clicks a link whose name holds the words, a suggestion first
//   ["see", "<words>"]       waits until the words show
// Optional: "width" (CSS pixels, 390 by default), "screenshot" (a file to save the page to).
// Ends with {"done": {"url", "scrollWidth", "html", "cookies", "storage"}}.
const fs = require('node:fs');
const path = require('node:path');
const readline = require('node:readline');
const { chromium } = require('playwright');

const types = { '.css': 'text/css', '.js': 'text/javascript', '.woff2': 'font/woff2', '.svg': 'image/svg+xml', '.png': 'image/png', '.ico': 'image/x-icon' };
const ORIGIN = 'http://help.test';

const answers = new Map();
let nextId = 1;
readline.createInterface({ input: process.stdin }).on('line', (line) => {
    const answer = JSON.parse(line);
    answers.get(answer.id)(answer);
    answers.delete(answer.id);
});

function ask(request) {
    const id = nextId++;
    return new Promise((resolve) => {
        answers.set(id, resolve);
        process.stdout.write(JSON.stringify({ request: { id, ...request } }) + '\n');
    });
}

(async () => {
    const job = JSON.parse(process.argv[2]);
    const browser = await chromium.launch();
    let exitCode = 0;
    try {
        const context = await browser.newContext({ userAgent: job.userAgent, viewport: { width: job.width || 390, height: 844 } });
        const page = await context.newPage();

        let open = 0;
        page.on('request', () => { open++; });
        page.on('requestfinished', () => { open--; });
        page.on('requestfailed', () => { open--; });
        // Until nothing is asked for a little while: the script's answers, and any page it opens.
        const quiet = async () => {
            for (let still = 0, waited = 0; still < 4 && waited < 15000; waited += 25) {
                await new Promise((resolve) => setTimeout(resolve, 25));
                still = open === 0 ? still + 1 : 0;
            }
            await page.waitForLoadState('load');
        };

        await page.route('**/*', async (route) => {
            const request = route.request();
            const url = new URL(request.url());
            if (url.origin !== ORIGIN) {
                return route.fulfill({ status: 404, body: '' });
            }
            const file = path.join(job.public, path.normalize(url.pathname));
            if (request.method() === 'GET' && file.startsWith(job.public + path.sep) && fs.existsSync(file) && fs.statSync(file).isFile()) {
                return route.fulfill({ contentType: types[path.extname(file)] || 'application/octet-stream', body: fs.readFileSync(file) });
            }
            const body = request.postDataBuffer();
            const answer = await ask({
                method: request.method(),
                url: url.href,
                headers: await request.allHeaders(),
                body: body ? body.toString('base64') : null,
            });
            const location = Object.entries(answer.headers).find(([name]) => name.toLowerCase() === 'location');
            if (request.isNavigationRequest() && answer.status >= 300 && answer.status < 400 && location) {
                // Chromium won't follow a redirect a route fulfils for a page, so the page follows it.
                return route.fulfill({ contentType: 'text/html', body: '<!DOCTYPE html><script>location.replace(' + JSON.stringify(location[1]) + ')</script>' });
            }
            return route.fulfill({ status: answer.status, headers: answer.headers, body: Buffer.from(answer.body, 'base64') });
        });

        // The page's clock stands still unless a step lets it run.
        await page.clock.install({ time: new Date('2026-11-03T10:00:00Z') });
        await page.clock.pauseAt(new Date('2026-11-03T10:00:01Z'));
        await page.goto(ORIGIN + job.open, { waitUntil: 'load' });
        await quiet();

        const box = () => page.locator('form.search input[name="q"]').first();
        for (const [action, what] of job.steps) {
            if (action === 'type') {
                await box().focus();
                await page.keyboard.type(what);
            } else if (action === 'press') {
                await box().press(what);
            } else if (action === 'idle') {
                await page.clock.runFor(what);
            } else if (action === 'click') {
                await page.getByRole('button', { name: what, exact: true }).click();
            } else if (action === 'open') {
                const suggested = page.locator('.suggestions').getByRole('link', { name: what });
                await (await suggested.count() > 0 ? suggested : page.getByRole('link', { name: what })).first().click();
            } else if (action === 'see') {
                await page.getByText(what, { exact: true }).first().waitFor({ timeout: 10000 });
            } else {
                throw new Error('Unknown step ' + action);
            }
            await quiet();
        }

        const storage = await page.evaluate(async () => ({
            local: localStorage.length,
            session: sessionStorage.length,
            indexedDb: (await indexedDB.databases()).length,
        }));
        if (job.screenshot) {
            await page.screenshot({ path: job.screenshot, fullPage: true });
        }
        process.stdout.write(JSON.stringify({ done: {
            url: page.url().replace(ORIGIN, ''),
            scrollWidth: await page.evaluate(() => document.documentElement.scrollWidth),
            html: await page.content(),
            cookies: (await context.cookies()).length,
            storage,
        } }) + '\n');
    } catch (error) {
        process.stderr.write(String(error && error.stack || error));
        exitCode = 1;
    } finally {
        await browser.close();
        process.exit(exitCode);
    }
})();
