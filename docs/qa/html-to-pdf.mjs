/**
 * Render a local HTML file to PDF using the cached headless Chrome shell.
 *
 * Reuses the CDP client from docs/qa/screenshot-viewports.mjs rather than
 * pulling in a PDF library for one document. Page.printToPDF gives real print
 * typography, honours @page margins, and needs no dependency.
 *
 *   node html-to-pdf.mjs <input.html> <output.pdf> "<footer title>" [word-for-page] [word-for-of]
 *
 * The last two default to English. The Filipino guide passes "Pahina" and "ng",
 * because a footer reading "Page 4 of 10" under a document written entirely in
 * Filipino is the sort of detail a client notices and nobody fixes.
 */

import { spawn } from 'node:child_process';
import { createHash, randomBytes } from 'node:crypto';
import { connect } from 'node:net';
import { writeFileSync } from 'node:fs';
import { resolve } from 'node:path';

const SHELL =
    process.env.HOME +
    '/Library/Caches/ms-playwright/chromium_headless_shell-1234/chrome-headless-shell-mac-arm64/chrome-headless-shell';

const PORT = 9444;
const INPUT = resolve(process.argv[2]);
const OUTPUT = resolve(process.argv[3]);
const TITLE = process.argv[4] ?? '';
const WORD_PAGE = process.argv[5] ?? 'Page';
const WORD_OF = process.argv[6] ?? 'of';

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
        const data = Buffer.from(JSON.stringify({ id, method, params, sessionId }));
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

        this.sock.write(Buffer.concat([Buffer.from(header), mask, masked]));

        return new Promise((resolve) => this.pending.set(id, resolve));
    }
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const chrome = spawn(SHELL, [
    `--remote-debugging-port=${PORT}`,
    '--headless=new',
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

const { targetId } = await ws.send('Target.createTarget', { url: 'about:blank' });
const { sessionId } = await ws.send('Target.attachToTarget', {
    targetId,
    flatten: true,
});

await ws.send('Page.enable', {}, sessionId);
await ws.send('Page.navigate', { url: 'file://' + INPUT }, sessionId);
await sleep(1500);

const { data } = await ws.send(
    'Page.printToPDF',
    {
        printBackground: true,
        preferCSSPageSize: true,
        displayHeaderFooter: true,
        headerTemplate: '<div></div>',
        // Page numbers and a running title, so a printed copy passed around an
        // office can be reassembled if the staple comes out.
        footerTemplate: `
            <div style="width:100%;padding:0 16mm;font-family:Helvetica,Arial,sans-serif;
                        font-size:7.5pt;color:#8A9AAE;display:flex;
                        justify-content:space-between;">
                <span>${TITLE}</span>
                <span>${WORD_PAGE} <span class="pageNumber"></span> ${WORD_OF} <span class="totalPages"></span></span>
            </div>`,
    },
    sessionId,
);

writeFileSync(OUTPUT, Buffer.from(data, 'base64'));
console.log('wrote ' + OUTPUT);

chrome.kill();
process.exit(0);
