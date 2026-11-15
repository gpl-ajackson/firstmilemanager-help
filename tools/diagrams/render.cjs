// Draws Mermaid diagrams as double-density PNG images in headless Chromium, for the content
// rebuild (App\Pictures\DiagramDrawer runs it). Mermaid runs here, once per changed diagram,
// so no diagram script ever reaches a reader.
//
//   echo '{"fonts": "/path/public/fonts", "diagrams": [{"source": "flowchart LR\n A --> B", "file": "/tmp/a.png"}]}' \
//     | node render.cjs
//
// Prints one result per diagram, in order: {"width": n, "height": n} (CSS pixels; the PNG is
// twice that) or {"error": "what Mermaid said"}. Exits 1 only when it can't draw at all.
//
// Diagrams use the Bay brand (brand/tokens.css): ink lines and words on white and panel grey,
// in Atkinson Hyperlegible Next, the help centre's text font.
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

const mermaidScript = require.resolve('mermaid/dist/mermaid.min.js');

const config = {
    startOnLoad: false,
    securityLevel: 'strict',
    theme: 'base',
    htmlLabels: false,
    flowchart: { htmlLabels: false, useMaxWidth: false },
    sequence: { useMaxWidth: false },
    state: { useMaxWidth: false },
    er: { useMaxWidth: false },
    themeVariables: {
        fontFamily: '"Atkinson Hyperlegible Next", sans-serif',
        fontSize: '16px',
        background: '#FFFFFF',
        primaryColor: '#F2F3F5',
        primaryTextColor: '#12151C',
        primaryBorderColor: '#12151C',
        secondaryColor: '#FFF3C4',
        secondaryTextColor: '#12151C',
        secondaryBorderColor: '#12151C',
        tertiaryColor: '#FFFFFF',
        tertiaryTextColor: '#12151C',
        tertiaryBorderColor: '#80868F',
        lineColor: '#50555F',
        textColor: '#12151C',
        noteBkgColor: '#FFF3C4',
        noteTextColor: '#12151C',
        noteBorderColor: '#80868F',
    },
};

const page = () => `<!DOCTYPE html>
<html><head><meta charset="utf-8"><style>
@font-face { font-family: "Atkinson Hyperlegible Next"; src: url("/fonts/AtkinsonHyperlegibleNext-Variable.woff2") format("woff2"); font-weight: 200 800; }
html, body { margin: 0; background: #FFFFFF; }
#diagram { display: inline-block; padding: 16px; background: #FFFFFF; }
#diagram svg { display: block; }
</style></head><body><div id="diagram"></div></body></html>`;

async function readStdin() {
    const chunks = [];
    for await (const chunk of process.stdin) {
        chunks.push(chunk);
    }
    return JSON.parse(Buffer.concat(chunks).toString('utf8'));
}

(async () => {
    const job = await readStdin();
    const browser = await chromium.launch();
    const results = [];
    try {
        const tab = await browser.newPage({ deviceScaleFactor: 2, viewport: { width: 1280, height: 800 } });
        await tab.route('**/*', async (route) => {
            const url = new URL(route.request().url());
            if (url.pathname === '/__diagram') {
                return route.fulfill({ contentType: 'text/html; charset=utf-8', body: page() });
            }
            const font = path.join(job.fonts, path.basename(url.pathname));
            if (url.pathname.startsWith('/fonts/') && fs.existsSync(font)) {
                return route.fulfill({ contentType: 'font/woff2', body: fs.readFileSync(font) });
            }
            return route.fulfill({ status: 404, body: '' });
        });
        await tab.goto('http://diagrams.test/__diagram', { waitUntil: 'load' });
        await tab.addScriptTag({ path: mermaidScript });
        await tab.evaluate(async (config) => {
            window.mermaid.initialize(config);
            await document.fonts.load('16px "Atkinson Hyperlegible Next"');
        }, config);

        for (const [index, diagram] of job.diagrams.entries()) {
            const drawn = await tab.evaluate(async ({ source, id }) => {
                const holder = document.getElementById('diagram');
                holder.innerHTML = '';
                try {
                    const { svg } = await window.mermaid.render(id, source);
                    holder.innerHTML = svg;
                    return { ok: true };
                } catch (error) {
                    document.getElementById(id)?.remove();
                    document.getElementById('d' + id)?.remove();
                    return { ok: false, error: String(error && error.message || error) };
                }
            }, { source: diagram.source, id: 'diagram-' + index });

            if (!drawn.ok) {
                results.push({ error: drawn.error.split('\n').map((line) => line.trim()).filter(Boolean).join(' ') });
                continue;
            }
            const holder = tab.locator('#diagram');
            const box = await holder.boundingBox();
            fs.mkdirSync(path.dirname(diagram.file), { recursive: true });
            await holder.screenshot({ path: diagram.file, type: 'png', animations: 'disabled' });
            results.push({ width: Math.round(box.width), height: Math.round(box.height) });
        }
    } finally {
        await browser.close();
    }
    process.stdout.write(JSON.stringify(results));
})().catch((error) => {
    process.stderr.write(String(error && error.stack || error));
    process.exit(1);
});
