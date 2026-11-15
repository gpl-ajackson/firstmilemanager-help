// Types words into a page's search box and presses its Search button in headless Chromium with
// script turned off, and reports the address the browser asked for (ScriptlessBrowser.php runs
// it). Like layout.cjs, the page comes from a file and its styles from public/; the request the
// form makes is caught rather than served, so no server is needed.
//
//   node submit-search.cjs '{"html": "/tmp/page.html", "public": "/path/public", "words": "palet"}'
//
// Prints {"requested": "/search?q=palet", "method": "GET"}.
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

const types = { '.css': 'text/css', '.woff2': 'font/woff2', '.svg': 'image/svg+xml', '.png': 'image/png', '.ico': 'image/x-icon' };

(async () => {
    const job = JSON.parse(process.argv[2]);
    const browser = await chromium.launch();
    try {
        const page = await browser.newPage({ javaScriptEnabled: false });
        let requested = null;
        await page.route('**/*', async (route) => {
            const request = route.request();
            const url = new URL(request.url());
            if (url.pathname === '/__page') {
                return route.fulfill({ contentType: 'text/html; charset=utf-8', body: fs.readFileSync(job.html) });
            }
            if (request.isNavigationRequest()) {
                requested = { requested: url.pathname + url.search, method: request.method() };
                return route.fulfill({ contentType: 'text/html; charset=utf-8', body: '<!DOCTYPE html><title>Submitted</title>' });
            }
            const file = path.join(job.public, path.normalize(url.pathname));
            if (url.host === 'help.test' && file.startsWith(job.public) && fs.existsSync(file) && fs.statSync(file).isFile()) {
                return route.fulfill({ contentType: types[path.extname(file)] || 'application/octet-stream', body: fs.readFileSync(file) });
            }
            return route.fulfill({ status: 404, body: '' });
        });
        await page.goto('http://help.test/__page', { waitUntil: 'load' });

        const form = page.getByRole('search');
        await form.getByRole('searchbox', { name: 'Search the help centre' }).fill(job.words);
        await form.getByRole('button', { name: 'Search' }).click();
        await page.waitForURL((url) => url.pathname !== '/__page');

        process.stdout.write(JSON.stringify(requested));
    } finally {
        await browser.close();
    }
})().catch((error) => {
    process.stderr.write(String(error && error.stack || error));
    process.exit(1);
});
