// Lays a page out in headless Chromium at one width and reports where its parts are, for
// tests about layout (BrowserLayout.php runs it). The page's own HTML comes from a file and
// every other request (styles, fonts, images) is served from public/ and the served pictures
// folder, so no server is needed. Stylesheets are read as they sit in public/, so their fonts
// come from /fonts/ there.
//
//   node layout.cjs '{"html": "/tmp/page.html", "public": "/path/public", "pictures": "/path/served-pictures", "width": 390, "selectors": [".article"]}'
//
// Prints {"scrollWidth": n, "viewportWidth": n, "boxes": {"<selector>": {x, y, width, height} | null}}.
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

const types = { '.css': 'text/css', '.woff2': 'font/woff2', '.svg': 'image/svg+xml', '.png': 'image/png', '.webp': 'image/webp', '.ico': 'image/x-icon' };

(async () => {
    const job = JSON.parse(process.argv[2]);
    const browser = await chromium.launch();
    try {
        const page = await browser.newPage({ viewport: { width: job.width, height: 900 } });
        await page.route('**/*', async (route) => {
            const url = new URL(route.request().url());
            if (url.pathname === '/__page') {
                return route.fulfill({ contentType: 'text/html; charset=utf-8', body: fs.readFileSync(job.html) });
            }
            // Fingerprinted addresses: /assets/<fingerprint>/<file in public/>, and
            // /pictures/<fingerprint>/<file> from the served pictures folder.
            const picture = url.pathname.match(/^\/pictures\/([0-9a-f]{16}\/[a-z0-9-]+\.webp)$/);
            const file = picture
                ? path.join(job.pictures, picture[1])
                : path.join(job.public, path.normalize(url.pathname.replace(/^\/assets\/[0-9a-f]{16}\//, '/')));
            if (url.host === 'help.test' && (picture || file.startsWith(job.public)) && fs.existsSync(file) && fs.statSync(file).isFile()) {
                return route.fulfill({ contentType: types[path.extname(file)] || 'application/octet-stream', body: fs.readFileSync(file) });
            }
            return route.fulfill({ status: 404, body: '' });
        });
        await page.goto('http://help.test/__page', { waitUntil: 'load' });

        const result = await page.evaluate((selectors) => {
            const boxes = {};
            for (const selector of selectors) {
                const element = document.querySelector(selector);
                const box = element ? element.getBoundingClientRect() : null;
                boxes[selector] = box ? { x: box.x, y: box.y + window.scrollY, width: box.width, height: box.height } : null;
            }
            return { scrollWidth: document.documentElement.scrollWidth, viewportWidth: window.innerWidth, boxes };
        }, job.selectors);
        process.stdout.write(JSON.stringify(result));
    } finally {
        await browser.close();
    }
})().catch((error) => {
    process.stderr.write(String(error && error.stack || error));
    process.exit(1);
});
