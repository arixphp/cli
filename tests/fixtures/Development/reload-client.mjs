import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source = readFileSync(process.argv[2], 'utf8');

function browser(content) {
    const connections = [];
    let reloads = 0;
    let timer = null;
    const context = {
        URL,
        Symbol,
        document: { querySelector: () => (content === null ? null : { content }) },
        location: { href: 'http://127.0.0.1:8181/page', origin: 'http://127.0.0.1:8181', reload: () => reloads++ },
        EventSource: class {
            constructor(url) { this.url = url; connections.push(this); }
            close() { this.closed = true; }
        },
        setTimeout: callback => { timer = callback; return 1; },
        clearTimeout: () => { timer = null; },
        addEventListener: () => {},
    };
    const load = () => runInNewContext(`(() => { ${source} })()`, context);

    return { context, connections, load, flush: () => { timer?.(); timer = null; }, reloads: () => reloads };
}

for (const url of [null, '', 'https://foreign.example/mercure', 'not a valid url [']) {
    const page = browser(url);
    page.load();
    if (url === 'not a valid url [') continue;
    assert.equal(page.connections.length, 0);
}

const page = browser('/.well-known/mercure?match=arix-dev-test');
page.load();
page.load();
assert.equal(page.connections.length, 1);
assert.equal(page.connections[0].url, 'http://127.0.0.1:8181/.well-known/mercure?match=arix-dev-test');
for (const data of ['', '{}', '[]', 'not-json', '[{}]']) {
    page.connections[0].onmessage({ data });
    page.flush();
    assert.equal(page.reloads(), 0);
}
page.connections[0].onmessage({ data: '[{"path_name":"styles/app.css"}]' });
page.connections[0].onmessage({ data: '[{"associated_path_name":"scripts/app.js"}]' });
page.flush();
assert.equal(page.reloads(), 1);
console.log('Reload client behavior passed');
