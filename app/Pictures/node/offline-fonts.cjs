/*
 * Preloaded into Stockist's asset build (NODE_OPTIONS=--require) only when the picture tool runs
 * with PICTURES_OFFLINE_FONTS=1, for sandboxes that can't reach fonts.bunny.net. It answers the
 * build's Bunny Fonts requests from the same families' @fontsource packages on npm, which the
 * tool installs beside Stockist first. GitHub Actions reaches Bunny Fonts and never loads this.
 */
const fs = require('node:fs');
const path = require('node:path');

const BUNNY = 'https://fonts.bunny.net/';
const realFetch = globalThis.fetch;

const slug = (family) => family.toLowerCase().replace(/\+/g, ' ').trim().replace(/\s+/g, '-');

function fontsourceFile(family, subset, weight, style) {
    const file = path.join(process.cwd(), 'node_modules', '@fontsource', family, 'files', `${family}-${subset}-${weight}-${style}.woff2`);
    if (!fs.existsSync(file)) {
        throw new Error(`Offline fonts: ${file} is missing; install @fontsource/${family} beside Stockist.`);
    }
    return file;
}

function css(url) {
    // css2?family=Instrument+Sans:wght@400;500;600&display=swap&subset=latin
    const family = slug(url.searchParams.get('family').split(':')[0]);
    const axes = url.searchParams.get('family').split(':')[1] ?? 'wght@400';
    const [names, tuples] = axes.split('@');
    const italic = names.split(',').includes('ital');
    const subsets = (url.searchParams.get('subset') ?? 'latin').split(',');
    const rules = [];
    for (const tuple of tuples.split(';')) {
        const parts = tuple.split(',');
        const style = italic && parts[0] === '1' ? 'italic' : 'normal';
        const weight = parts[parts.length - 1];
        for (const subset of subsets) {
            fontsourceFile(family, subset, weight, style);
            rules.push(`/* ${subset} */\n@font-face {\n  font-family: '${url.searchParams.get('family').split(':')[0].replace(/\+/g, ' ')}';\n  font-style: ${style};\n  font-weight: ${weight};\n  font-display: ${url.searchParams.get('display') ?? 'swap'};\n  src: url(${BUNNY}offline/${family}/${subset}/${weight}/${style}.woff2) format('woff2');\n}`);
        }
    }
    return rules.join('\n');
}

globalThis.fetch = async (input, init) => {
    const href = typeof input === 'string' ? input : input.url ?? String(input);
    if (!href.startsWith(BUNNY)) {
        return realFetch(input, init);
    }
    const url = new URL(href);
    if (url.pathname.startsWith('/offline/')) {
        const [, , family, subset, weight, style] = url.pathname.replace(/\.woff2$/, '').split('/');
        return new Response(fs.readFileSync(fontsourceFile(family, subset, weight, style)), { headers: { 'content-type': 'font/woff2' } });
    }
    return new Response(css(url), { headers: { 'content-type': 'text/css' } });
};
