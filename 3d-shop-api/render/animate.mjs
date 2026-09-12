// Reads /in/job.json and /in/model, writes one animated WebP per pass to /out.
//
// A third entrypoint beside render.mjs and proxy.mjs. An attested still has to
// be reproducible from the pinned image, so nothing here may touch the code
// that draws one. The page POSTs each frame back, so completion is observed.
import { spawn } from 'node:child_process';
import { createServer } from 'node:http';
import { readFile, writeFile } from 'node:fs/promises';
import { extname, join, resolve, sep } from 'node:path';

const IN = '/in';
const OUT = '/out';
const APP = '/app';
const PORT = 8712;
const TIMEOUT_MS = Number(process.env.ANIMATE_TIMEOUT ?? 600) * 1000;
const CHROME = process.env.CHROME_BIN ?? '/usr/bin/chromium';

const state = { frames: new Map(), failed: null, clips: [], chosen: null, done: false };
let settle;
const finished = new Promise((done) => { settle = done; });

const ASSET_TYPES = {
  '.png': 'image/png', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg',
  '.webp': 'image/webp', '.bmp': 'image/bmp', '.tga': 'image/x-tga',
  '.mtl': 'text/plain', '.bin': 'application/octet-stream',
  '.gltf': 'model/gltf+json', '.glb': 'model/gltf-binary', '.obj': 'text/plain',
};

const ROUTES = {
  '/': [join(APP, 'animate.html'), 'text/html'],
  '/animate.html': [join(APP, 'animate.html'), 'text/html'],
  '/job.json': [join(IN, 'job.json'), 'application/json'],
  '/model': [join(IN, 'model'), 'application/octet-stream'],
  '/fitToView.js': [join(APP, 'fitToView.js'), 'text/javascript'],
};

function readBody(request) {
  return new Promise((done, fail) => {
    const chunks = [];
    request.on('data', (chunk) => chunks.push(chunk));
    request.on('end', () => done(Buffer.concat(chunks)));
    request.on('error', fail);
  });
}

/** Confined for the same reason as in render.mjs: the model names these paths. */
function confinedRoute(path, prefix, root, types) {
  if (! path.startsWith(prefix)) {
    return null;
  }

  const resolved = resolve(join(root, decodeURIComponent(path.slice(prefix.length))));

  if (resolved !== root && ! resolved.startsWith(root + sep)) {
    return null;
  }

  return [resolved, types[extname(resolved).toLowerCase()] ?? 'application/octet-stream'];
}

// ------------------------------------------------------------- animated webp

/**
 * The pixels of one still WebP, without its file wrapper.
 *
 * A frame inside an animation is the same VP8 payload a one-image file holds,
 * so the RIFF header comes off and the image chunks go in as they are. ALPH
 * rides along with VP8 when the canvas had transparency.
 */
function frameChunks(still) {
  if (still.subarray(0, 4).toString('latin1') !== 'RIFF'
    || still.subarray(8, 12).toString('latin1') !== 'WEBP') {
    throw new Error('the browser did not return a WebP frame');
  }

  const kept = [];
  let at = 12;

  while (at + 8 <= still.length) {
    const type = still.subarray(at, at + 4).toString('latin1');
    const size = still.readUInt32LE(at + 4);
    const padded = size + (size % 2);

    if (type === 'VP8 ' || type === 'VP8L' || type === 'ALPH') {
      kept.push(still.subarray(at, at + 8 + padded));
    }

    at += 8 + padded;
  }

  if (kept.length === 0) {
    throw new Error('a WebP frame carried no image data');
  }

  return Buffer.concat(kept);
}

function chunk(type, payload) {
  const header = Buffer.alloc(8);
  header.write(type, 0, 'latin1');
  header.writeUInt32LE(payload.length, 4);

  // Every RIFF chunk is padded to an even length.
  return payload.length % 2 === 0
    ? Buffer.concat([header, payload])
    : Buffer.concat([header, payload, Buffer.of(0)]);
}

function uint24(value) {
  const out = Buffer.alloc(3);
  out.writeUIntLE(value, 0, 3);

  return out;
}

/**
 * Stitches still frames into one animated WebP.
 *
 * By hand because no browser API encodes an animation and the image carries no
 * video tools. The format is a short list of RIFF chunks, so this needs nothing
 * the image does not already have.
 */
function animatedWebp(stills, width, height, frameMs) {
  const hasAlpha = stills.some((one) => one.includes('ALPH'));

  const vp8x = chunk('VP8X', Buffer.concat([
    // Animation, and alpha when any frame carried it.
    Buffer.of(0x02 | (hasAlpha ? 0x10 : 0x00), 0, 0, 0),
    uint24(width - 1),
    uint24(height - 1),
  ]));

  const anim = chunk('ANIM', Buffer.concat([
    Buffer.of(0xff, 0xff, 0xff, 0xff),
    Buffer.of(0x00, 0x00), // Loop forever.
  ]));

  const frames = stills.map((still) => chunk('ANMF', Buffer.concat([
    uint24(0), uint24(0),
    uint24(width - 1), uint24(height - 1),
    uint24(frameMs),
    Buffer.of(0x00), // Blend with the frame before, do not clear it first.
    frameChunks(still),
  ])));

  const body = Buffer.concat([Buffer.from('WEBP', 'latin1'), vp8x, anim, ...frames]);
  const riff = Buffer.alloc(8);
  riff.write('RIFF', 0, 'latin1');
  riff.writeUInt32LE(body.length, 4);

  return Buffer.concat([riff, body]);
}

// ------------------------------------------------------------------- serving

const server = createServer(async (request, response) => {
  const path = request.url.split('?')[0];

  if (request.method === 'POST') {
    const body = JSON.parse((await readBody(request)).toString() || '{}');

    if (path === '/frame') {
      const pass = body.pass ?? 'shaded';
      const list = state.frames.get(pass) ?? [];
      list[body.index] = Buffer.from(body.webp.split(',')[1], 'base64');
      state.frames.set(pass, list);
    } else if (path === '/done') {
      state.clips = body.clips ?? [];
      state.chosen = body.chosen ?? null;
      state.done = true;
    } else if (path === '/failed') {
      state.failed = String(body.reason ?? 'The clip could not be drawn.').slice(0, 300);
    }

    response.writeHead(204).end();

    if (path === '/done' || path === '/failed') {
      settle();
    }

    return;
  }

  const route = ROUTES[path]
    ?? confinedRoute(path, '/three/', join(APP, 'three'), { '.js': 'text/javascript' })
    ?? confinedRoute(path, '/bundle/', join(IN, 'bundle'), ASSET_TYPES);

  if (route === null) {
    response.writeHead(404).end();

    return;
  }

  try {
    const body = await readFile(route[0]);
    response.writeHead(200, { 'content-type': route[1] }).end(body);
  } catch {
    response.writeHead(404).end();
  }
});

async function writeResult(result) {
  await writeFile(join(OUT, 'result.json'), JSON.stringify(result, null, 2));
  console.log(JSON.stringify(result));
}

async function main() {
  const job = JSON.parse(await readFile(join(IN, 'job.json'), 'utf8'));
  const frames = Number(job.frames ?? 24);
  const width = Number(job.output?.width ?? 512);
  const height = Number(job.output?.height ?? 512);

  if (! Number.isInteger(frames) || frames < 2 || frames > 120) {
    await writeResult({ status: 'failed', reason: 'A clip needs between 2 and 120 frames.', retryable: false });

    return 2;
  }

  await new Promise((listening) => server.listen(PORT, '127.0.0.1', listening));

  const chrome = spawn(CHROME, [
    '--headless=new', '--disable-gpu',
    '--use-angle=swiftshader', '--enable-unsafe-swiftshader',
    '--no-sandbox', '--disable-dev-shm-usage',
    '--hide-scrollbars', '--force-device-scale-factor=1',
    '--user-data-dir=/tmp/chrome',
    `http://127.0.0.1:${PORT}/animate.html`,
  ], { stdio: ['ignore', 'ignore', 'pipe'] });

  let stderr = '';
  chrome.stderr.on('data', (piece) => { stderr = (stderr + piece).slice(-2000); });

  const startedAt = Date.now();
  const timedOut = await Promise.race([
    finished.then(() => false),
    new Promise((give) => setTimeout(() => give(true), TIMEOUT_MS)),
  ]);
  const seconds = Number(((Date.now() - startedAt) / 1000).toFixed(2));

  chrome.kill('SIGTERM');

  if (timedOut) {
    await writeResult({ status: 'failed', reason: 'Drawing the clip timed out.', retryable: true, seconds, stderr: stderr.slice(-600) });

    return 3;
  }

  if (state.failed !== null) {
    await writeResult({ status: 'failed', reason: state.failed, retryable: false, seconds });

    return 4;
  }

  const clipMs = Math.max(1, Math.round((state.chosen?.seconds ?? 1) * 1000));
  const frameMs = Math.max(10, Math.round(clipMs / frames));
  const written = [];

  for (const [pass, stills] of state.frames) {
    const complete = stills.filter(Boolean);

    if (complete.length !== frames) {
      await writeResult({
        status: 'failed',
        reason: `The ${pass} pass drew ${complete.length} of ${frames} frames.`,
        retryable: true,
        seconds,
      });

      return 5;
    }

    const file = `clip-${pass}.webp`;
    const animation = animatedWebp(complete, width, height, frameMs);
    await writeFile(join(OUT, file), animation);

    written.push({ pass, file, bytes: animation.length });
  }

  if (written.length === 0) {
    await writeResult({ status: 'failed', reason: 'No pass drew anything.', retryable: true, seconds });

    return 6;
  }

  await writeResult({
    status: 'ok',
    files: written,
    clip: state.chosen,
    clips: state.clips,
    frames,
    frameMs,
    width,
    height,
    seconds,
  });

  return 0;
}

main()
  .then((code) => process.exit(code))
  .catch(async (error) => {
    await writeResult({ status: 'failed', reason: String(error?.message ?? error).slice(0, 300), retryable: true });
    process.exit(1);
  });
