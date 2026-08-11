/**
 * Screenshot the app at real viewport widths using the cached headless Chrome
 * shell, driven over the DevTools protocol.
 *
 * The browser-automation extension cannot reach this dev server, so this exists
 * to actually SEE the pages rather than reasoning about class names. It speaks
 * raw CDP over a WebSocket implemented here, because the project has no
 * websocket dependency and adding one for a QA script would be silly.
 */

import { spawn } from 'node:child_process';
import { createHash, randomBytes } from 'node:crypto';
import { connect } from 'node:net';
import { mkdirSync, writeFileSync } from 'node:fs';

const SHELL =
    process.env.HOME +
    '/Library/Caches/ms-playwright/chromium_headless_shell-1234/chrome-headless-shell-mac-arm64/chrome-headless-shell';

const PORT = 9333;
const OUT = process.argv[2];
const SESSION = process.argv[3];
const BASE = "http://127.0.0.1:8100";

const VIEWPORTS = [
    { name: 'iphone-se', width: 375, height: 667, dpr: 2 },
    { name: 'iphone-14', width: 390, height: 844, dpr: 3 },
    { name: 'tablet', width: 768, height: 1024, dpr: 2 },
    { name: 'laptop', width: 1280, height: 800, dpr: 1 },
];

const PAGES = JSON.parse(process.env.QA_PAGES ?? '[]');

mkdirSync(OUT, { recursive: true });

// ---------------------------------------------------------------- websocket

/** A minimal RFC 6455 client. Text frames only, no extensions, no fragmentation. */
class Socket {
    constructor(url) {
        const { hostname, port, pathname } = new URL(url);
        this.pending = new Map();
        this.id = 0;
        this.buffer = Buffer.alloc(0);
        this.ready = new Promise((resolve, reject) => {
            this.sock = connect(Number(port), hostname, () => {
                const key = randomBytes(16).toString('base64');
                this.accept = createHash('sha1')
                    .update(key + '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')
                    .digest('base64');
                this.sock.write(
                    `GET ${pathname} HTTP/1.1\r\nHost: ${hostname}:${port}\r\n` +
                        `Upgrade: websocket\r\nConnection: Upgrade\r\n` +
                        `Sec-WebSocket-Key: ${key}\r\nSec-WebSocket-Version: 13\r\n\r\n`,
                );
            });
            this.sock.on('error', reject);
            this.sock.once('data', (chunk) => {
                const split = chunk.indexOf('\r\n\r\n');
                if (!chunk.subarray(0, split).includes('101')) {
                    reject(new Error('handshake failed'));

                    return;
                }
                this.buffer = chunk.subarray(split + 4);
                this.sock.on('data', (d) => this.read(d));
                this.drain();
                resolve();
            });
        });
    }

    read(chunk) {
        this.buffer = Buffer.concat([this.buffer, chunk]);
        this.drain();
    }

    drain() {
        for (;;) {
            if (this.buffer.length < 2) return;
            const len0 = this.buffer[1] & 0x7f;
            let offset = 2;
            let length = len0;

            if (len0 === 126) {
                if (this.buffer.length < 4) return;
                length = this.buffer.readUInt16BE(2);
                offset = 4;
            } else if (len0 === 127) {
                if (this.buffer.length < 10) return;
                length = Number(this.buffer.readBigUInt64BE(2));
                offset = 10;
            }

            if (this.buffer.length < offset + length) return;

            const payload = this.buffer.subarray(offset, offset + length);
            this.buffer = this.buffer.subarray(offset + length);

            let message;
            try {
                message = JSON.parse(payload.toString());
            } catch {
                continue;
            }

            const resolve = this.pending.get(message.id);
            if (resolve) {
                this.pending.delete(message.id);
                resolve(message.result ?? {});
            }
        }
    }

    send(method, params = {}, sessionId) {
        const id = ++this.id;
        const body = JSON.stringify({ id, method, params, sessionId });
        const data = Buffer.from(body);
        const mask = randomBytes(4);
        const header = [];

        header.push(0x81);
        if (data.length < 126) header.push(0x80 | data.length);
        else if (data.length < 65536) {
            header.push(0x80 | 126, data.length >> 8, data.length & 0xff);
        } else {
            header.push(0x80 | 127, 0, 0, 0, 0);
            header.push(
                (data.length >> 24) & 0xff,
                (data.length >> 16) & 0xff,
                (data.length >> 8) & 0xff,
                data.length & 0xff,
            );
        }

        const masked = Buffer.from(data);
        for (let i = 0; i < masked.length; i++) masked[i] ^= mask[i % 4];

        this.sock.write(
            Buffer.concat([Buffer.from(header), mask, masked]),
        );

        return new Promise((resolve) => this.pending.set(id, resolve));
    }
}

// ---------------------------------------------------------------- run

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const chrome = spawn(SHELL, [
    `--remote-debugging-port=${PORT}`,
    '--headless=new',
    '--hide-scrollbars',
    '--no-sandbox',
    '--disable-gpu',
    '--force-color-profile=srgb',
    'about:blank',
]);

chrome.stderr.on('data', () => {});

let target = null;
for (let i = 0; i < 40 && !target; i++) {
    await sleep(250);
    try {
        const res = await fetch(`http://127.0.0.1:${PORT}/json/version`);
        target = (await res.json()).webSocketDebuggerUrl;
    } catch {
        /* not up yet */
    }
}

if (!target) {
    chrome.kill();
    throw new Error('headless chrome did not start');
}

const ws = new Socket(target);
await ws.ready;

const { targetId } = await ws.send('Target.createTarget', {
    url: 'about:blank',
});
const { sessionId } = await ws.send('Target.attachToTarget', {
    targetId,
    flatten: true,
});

await ws.send('Page.enable', {}, sessionId);
await ws.send('Network.enable', {}, sessionId);
if (SESSION !== 'none') {
    await ws.send(
        'Network.setCookie',
        {
            name: 'cicto-session',
            value: SESSION,
            domain: '127.0.0.1',
            path: '/',
        },
        sessionId,
    );
}

const report = [];

for (const view of VIEWPORTS) {
    await ws.send(
        'Emulation.setDeviceMetricsOverride',
        {
            width: view.width,
            height: view.height,
            deviceScaleFactor: 1,
            mobile: view.width < 768,
        },
        sessionId,
    );

    for (const page of PAGES) {
        await ws.send('Page.navigate', { url: BASE + page.path }, sessionId);
        await sleep(1400);

        // Measure overflow and the real document height before shooting.
        const { result } = await ws.send(
            'Runtime.evaluate',
            {
                expression: `JSON.stringify({
                    scrollW: document.documentElement.scrollWidth,
                    clientW: document.documentElement.clientWidth,
                    scrollH: document.documentElement.scrollHeight,
                    title: document.title,
                    offenders: Array.from(document.querySelectorAll('*'))
                        .filter(el => {
                            const r = el.getBoundingClientRect();
                            return r.width > 0 && (r.right > document.documentElement.clientWidth + 1 || r.left < -1);
                        })
                        .slice(0, 6)
                        .map(el => (el.tagName.toLowerCase() + '.' + String(el.className || '').split(' ').slice(0,3).join('.')).slice(0, 90)),
                })`,
                returnByValue: true,
            },
            sessionId,
        );

        const metrics = JSON.parse(result.value);

        await ws.send(
            'Emulation.setDeviceMetricsOverride',
            {
                width: view.width,
                height: Math.min(metrics.scrollH, 4000),
                deviceScaleFactor: 1,
                mobile: view.width < 768,
            },
            sessionId,
        );
        await sleep(350);

        const shot = await ws.send(
            'Page.captureScreenshot',
            { format: 'png', captureBeyondViewport: true },
            sessionId,
        );

        const file = `${OUT}/${page.name}-${view.name}.png`;
        writeFileSync(file, Buffer.from(shot.data, 'base64'));

        report.push({
            page: page.name,
            view: view.name,
            width: view.width,
            overflowBy: metrics.scrollW - metrics.clientW,
            height: metrics.scrollH,
            offenders: metrics.offenders,
        });

        // Restore for the next measurement pass.
        await ws.send(
            'Emulation.setDeviceMetricsOverride',
            {
                width: view.width,
                height: view.height,
                deviceScaleFactor: 1,
                mobile: view.width < 768,
            },
            sessionId,
        );
    }
}

writeFileSync(`${OUT}/report.json`, JSON.stringify(report, null, 2));

console.log('viewport  page      overflow  height  offenders');
for (const row of report) {
    const flag = row.overflowBy > 0 ? `+${row.overflowBy}px` : 'none';
    console.log(
        `${row.view.padEnd(10)}${row.page.padEnd(10)}${flag.padEnd(10)}${String(row.height).padEnd(8)}${row.offenders.join(' | ')}`,
    );
}

chrome.kill();
process.exit(0);
