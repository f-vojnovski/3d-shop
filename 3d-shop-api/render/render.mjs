// Reads /in/job.json and /in/model, writes PNGs and result.json to /out.
// Chrome POSTs each image back, so completion is observed, not timed.
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { execFileSync, spawn } from 'node:child_process';
import { createServer } from 'node:http';
import { readFile, writeFile } from 'node:fs/promises';
import { extname, join, resolve, sep } from 'node:path';

const IN = '/in';
const OUT = '/out';
const APP = '/app';
const PORT = 8710;
const TIMEOUT_MS = Number(process.env.RENDER_TIMEOUT ?? 180) * 1000;
const CHROME = process.env.CHROME_BIN ?? '/usr/bin/chromium';

// Names the code that drew the pixels, not just the libraries: rebuild the
// image from the pinned versions and this digest has to come out the same.
function harnessDigest() {
  try {
    const hash = createHash('sha256');

    for (const file of ['/app/harness.html', '/app/render.mjs', '/app/fitToView.js']) {
      hash.update(readFileSync(file));
    }

    return hash.digest('hex').slice(0, 16);
  } catch {
    return 'unknown';
  }
}

/** An attested image is only checkable if the record names the exact renderer. */
function rendererIdentity() {
  let browser = 'unknown';

  try {
    browser = execFileSync(CHROME, ['--version'], { encoding: 'utf8' }).trim();
  } catch {
    // Reported as unknown rather than failing a render that already succeeded.
  }

  return {
    engine: 'three.js',
    three: process.env.THREE_VERSION ?? 'unknown',
    browser,
    rasterizer: 'swiftshader',
    harness: harnessDigest(),
  };
}

const MIN_COVERAGE = 0.001;

const state = { images: new Map(), failed: null, triangles: 0, wireframes: true, done: false };
let settle;
const finished = new Promise((resolve) => { settle = resolve; });

const TYPES = { '.html': 'text/html', '.js': 'text/javascript', '.mjs': 'text/javascript', '.json': 'application/json' };

const ROUTES = {
  '/': [join(APP, 'harness.html'), 'text/html'],
  '/harness.html': [join(APP, 'harness.html'), 'text/html'],
  '/job.json': [join(IN, 'job.json'), 'application/json'],
  '/model': [join(IN, 'model'), 'application/octet-stream'],
  '/fitToView.js': [join(APP, 'fitToView.js'), 'text/javascript'],
};

function readBody(request) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    request.on('data', (chunk) => chunks.push(chunk));
    request.on('end', () => resolve(Buffer.concat(chunks)));
    request.on('error', reject);
  });
}

/**
 * `/three/*` is the only path built from the request, and a model can ask the
 * page to fetch arbitrary URIs, so the resolved path is confined to /app rather
 * than trusted to contain no traversal.
 */
function libraryRoute(path) {
  if (! path.startsWith('/three/')) {
    return null;
  }

  const resolved = resolve(join(APP, path.slice(1)));

  if (resolved !== join(APP, 'three') && ! resolved.startsWith(join(APP, 'three') + sep)) {
    return null;
  }

  return [resolved, TYPES[extname(path)] ?? 'text/javascript'];
}

const server = createServer(async (request, response) => {
  const path = request.url.split('?')[0];

  if (request.method === 'POST') {
    const body = JSON.parse((await readBody(request)).toString() || '{}');

    if (path === '/image') {
      const pass = body.pass ?? 'shaded';

      state.images.set(`${pass}:${body.index}`, {
        pass,
        index: body.index,
        png: Buffer.from(body.png.split(',')[1], 'base64'),
        coverage: body.coverage ?? 0,
      });
      state.triangles = body.triangles ?? 0;
    } else if (path === '/done') {
      state.done = true;
      state.wireframes = body.wireframes ?? true;
      settle();
    } else if (path === '/failed') {
      state.failed = body.reason ?? 'unknown';
      settle();
    }

    response.writeHead(204).end();
    return;
  }

  const route = ROUTES[path] ?? libraryRoute(path);

  if (route === null) {
    response.writeHead(404).end();
    return;
  }

  try {
    const data = await readFile(route[0]);
    response.writeHead(200, { 'Content-Type': route[1], 'Content-Length': data.length }).end(data);
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
  const expected = job.angles?.length ?? 0;

  if (expected === 0) {
    await writeResult({ status: 'failed', reason: 'No angles requested.', retryable: false });
    return 2;
  }

  await new Promise((resolve) => server.listen(PORT, '127.0.0.1', resolve));

  const chrome = spawn(CHROME, [
    '--headless=new', '--disable-gpu',
    '--use-angle=swiftshader', '--enable-unsafe-swiftshader',
    '--no-sandbox', '--disable-dev-shm-usage',
    '--hide-scrollbars', '--force-device-scale-factor=1',
    '--user-data-dir=/tmp/chrome',
    `http://127.0.0.1:${PORT}/harness.html`,
  ], { stdio: ['ignore', 'ignore', 'pipe'] });

  let stderr = '';
  chrome.stderr.on('data', (chunk) => { stderr = (stderr + chunk).slice(-2000); });

  const startedAt = Date.now();
  const timedOut = await Promise.race([
    finished.then(() => false),
    new Promise((resolve) => setTimeout(() => resolve(true), TIMEOUT_MS)),
  ]);
  const seconds = Number(((Date.now() - startedAt) / 1000).toFixed(2));

  chrome.kill('SIGTERM');

  if (timedOut) {
    await writeResult({ status: 'failed', reason: 'Rendering timed out.', retryable: true, seconds, stderr: stderr.slice(-600) });
    return 3;
  }

  if (state.failed !== null) {
    // Harness-reported failures are the model's fault, not the renderer's.
    await writeResult({ status: 'failed', reason: state.failed, retryable: false, seconds });
    return 4;
  }

  const images = [];
  const blank = [];

  const drawn = [...state.images.values()];
  // One entry per angle whichever pass was asked for, so wireframe leads alone.
  const lead = drawn.some((image) => image.pass === 'shaded') ? 'shaded' : 'wireframe';
  const primary = drawn
    .filter((image) => image.pass === lead)
    .sort((a, b) => a.index - b.index);

  for (const { index, png, coverage } of primary) {
    if (coverage < MIN_COVERAGE) {
      blank.push(index);
      continue;
    }

    const file = `${lead === 'shaded' ? 'angle' : 'wireframe'}-${index}.png`;
    await writeFile(join(OUT, file), png);

    const entry = { index, file, pass: lead, bytes: png.length, coverage: Number(coverage.toFixed(5)) };
    // Nested so a discarded angle takes its wireframe with it, and `blank`
    // keeps counting angles rather than images.
    const outline = lead === 'shaded' ? state.images.get(`wireframe:${index}`) : undefined;

    if (outline !== undefined && outline.coverage >= MIN_COVERAGE) {
      const wireframeFile = `wireframe-${index}.png`;
      await writeFile(join(OUT, wireframeFile), outline.png);
      entry.wireframe = {
        file: wireframeFile,
        bytes: outline.png.length,
        coverage: Number(outline.coverage.toFixed(5)),
      };
    }

    images.push(entry);
  }

  if (images.length === 0) {
    await writeResult({ status: 'failed', reason: 'Every rendered image was blank.', retryable: false, blank, seconds });
    return 5;
  }

  await writeResult({
    status: 'ok',
    images,
    blank,
    triangles: state.triangles,
    wireframes: state.wireframes,
    seconds,
    renderer: rendererIdentity(),
  });

  return 0;
}

main()
  .then((code) => process.exit(code))
  .catch(async (error) => {
    await writeResult({ status: 'failed', reason: String(error).slice(0, 300), retryable: true });
    process.exit(1);
  });
