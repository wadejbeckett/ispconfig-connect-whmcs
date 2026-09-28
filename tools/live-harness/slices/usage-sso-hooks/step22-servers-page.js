// Runs a WHMCS-shaped Configuration > Servers page (plus the hook's footer output) in jsdom
// with scripts enabled, and reports where the ISPConfig block landed and what the cells read.
// Input (stdin JSON): {pages: {name: {url, html}}}
'use strict';
const { JSDOM, VirtualConsole } = require('jsdom');
const input = JSON.parse(require('fs').readFileSync(0, 'utf8'));

(async () => {
    const results = {};
    for (const [name, page] of Object.entries(input.pages)) {
        const errors = [];
        const vc = new VirtualConsole();
        vc.on('jsdomError', (e) => errors.push(String(e && e.message ? e.message : e)));
        vc.on('error', (e) => errors.push(String(e)));
        const dom = new JSDOM(page.html, { url: page.url, runScripts: 'dangerously', virtualConsole: vc });
        await new Promise((r) => {
            if (dom.window.document.readyState === 'complete') { r(); } else { dom.window.addEventListener('load', r); }
        });
        const d = dom.window.document;
        const blocks = [...d.querySelectorAll('.ispconfigconnect-meta')].map((b) => {
            const cell = b.parentNode;
            const row = cell.parentNode;
            return {
                cellId: cell.id || null,
                cellIndex: row && row.cells ? [...row.cells].indexOf(cell) : null,
                rowId: row ? row.id || null : null,
                lines: [...b.children].map((l) => ({ text: l.textContent, cls: l.className || '', title: l.getAttribute('title') })),
                html: b.outerHTML,
            };
        });
        const usage = {};
        d.querySelectorAll('.server-usage-count').forEach((c) => {
            usage[c.id || c.parentNode.id] = { text: c.textContent, title: c.getAttribute('title') };
        });
        results[name] = {
            errors,
            blocks,
            usage,
            scripts: d.querySelectorAll('script').length,
            ispconfigScriptPresent: !!d.getElementById('ispconfigconnect-server-meta'),
            bodyTextLength: d.body.textContent.length,
            alertCalled: false,
        };
        dom.window.close();
    }
    process.stdout.write(JSON.stringify(results));
})().catch((e) => { process.stderr.write(String(e && e.stack || e)); process.exit(1); });
