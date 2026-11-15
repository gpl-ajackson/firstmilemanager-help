// A reader in headless Chromium, with script on or off, whose every request goes through the
// Laravel app under test (ReaderBrowser.php runs it). Files that exist in public/ (styles,
// fonts, images, the site's script) are served from disk; every other request is written to
// stdout as one JSON line, {"request": {...}}, and the answer is read back from stdin as one
// JSON line, so the test's own app (and its database transaction) answers it.
//
//   node reader.cjs '{"public": "/path/public", "userAgent": "Mozilla/5.0 ...", "script": true, "open": "/articles/x", "steps": [...]}'
//
// Steps, in order: ["click", "<button name>"], ["choose", "<radio label>"],
// ["fill", "<text box label>", "<text>"], ["see", "<words>"] (waits until they show).
// "lose": ["POST /feedback/x"] drops the connection the first time the app has answered that
// request, as when a reader's connection fails after the server recorded it.
// Ends with {"done": {"url", "html", "cookies", "storage"}}.
const fs = require('node:fs');
const path = require('node:path');
const readline = require('node:readline');
const { chromium } = require('playwright');

const types = { '.css': 'text/css', '.js': 'text/javascript', '.woff2': 'font/woff2', '.svg': 'image/svg+xml', '.png': 'image/png', '.ico': 'image/x-icon' };
const ORIGIN = 'http://help.test';

const answers = new Map();
const lost = new Set();
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
        const context = await browser.newContext({ javaScriptEnabled: job.script, userAgent: job.userAgent, viewport: { width: 1280, height: 900 } });
        const page = await context.newPage();
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
            const asked = request.method() + ' ' + url.pathname;
            if ((job.lose || []).includes(asked) && !lost.has(asked)) {
                // The app has answered (and recorded); the answer never reaches the page.
                lost.add(asked);
                return route.abort('connectionreset');
            }
            return route.fulfill({ status: answer.status, headers: answer.headers, body: Buffer.from(answer.body, 'base64') });
        });

        await page.goto(ORIGIN + job.open, { waitUntil: 'load' });

        for (const [action, name, text] of job.steps) {
            if (action === 'click') {
                await page.getByRole('button', { name, exact: true }).click();
            } else if (action === 'choose') {
                await page.getByRole('radio', { name, exact: true }).check();
            } else if (action === 'fill') {
                await page.getByRole('textbox', { name }).fill(text);
            } else if (action === 'see') {
                await page.getByText(name, { exact: true }).first().waitFor({ timeout: 10000 });
            } else {
                throw new Error('Unknown step ' + action);
            }
        }
        await page.waitForLoadState('load');

        const storage = await page.evaluate(() => ({ local: localStorage.length, session: sessionStorage.length })).catch(() => null);
        process.stdout.write(JSON.stringify({ done: {
            url: page.url().replace(ORIGIN, ''),
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
