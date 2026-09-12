// Reads /in/job.json and /in/model, writes /out/proxy.glb and result.json.
//
// A separate entrypoint from render.mjs on purpose: an attested still has to be
// reproducible from the pinned image, and nothing here should be able to change
// what that path does. The page POSTs the finished glb back, so completion is
// observed rather than timed.
import { spawn } from 'node:child_process';
import { createServer } from 'node:http';
import { readFile, writeFile } from 'node:fs/promises';
import { extname, join, resolve, sep } from 'node:path';

const IN = '/in';
const OUT = '/out';
const APP = '/app';
const PORT = 8711;
const TIMEOUT_MS = Number(process.env.PROXY_TIMEOUT ?? 180) * 1000;
const CHROME = process.env.CHROME_BIN ?? '/usr/bin/chromium';

const state = { glb: null, failed: null, before: 0, after: 0, textures: 0, baked: null };

/**
 * What a proxy is allowed to contain. An allow-list rather than a list of
 * things to strip, so anything the exporter learns to emit later has to be
 * added here deliberately instead of shipping unnoticed.
 */
const ALLOWED = {
  top: ['asset', 'scene', 'scenes', 'nodes', 'meshes', 'materials',
    'accessors', 'bufferViews', 'buffers', 'images', 'samplers', 'textures'],
  asset: ['version', 'generator'],
  node: ['mesh', 'children', 'translation', 'rotation', 'scale', 'matrix'],
  mesh: ['primitives'],
  primitive: ['attributes', 'indices', 'material', 'mode'],
  attribute: ['POSITION', 'NORMAL', 'TANGENT', 'TEXCOORD_0', 'TEXCOORD_1', 'COLOR_0'],
  material: ['pbrMetallicRoughness', 'normalTexture', 'occlusionTexture',
    'emissiveTexture', 'emissiveFactor', 'alphaMode', 'alphaCutoff', 'doubleSided'],
};

function jsonChunkOf(glb) {
  let at = 12;

  while (at + 8 <= glb.length) {
    const length = glb.readUInt32LE(at);
    const type = glb.readUInt32LE(at + 4);

    if (type === 0x4e4f534a) {
      return JSON.parse(glb.subarray(at + 8, at + 8 + length).toString('utf8').replace(/\0+$/, ''));
    }

    at += 8 + length;
  }

  return null;
}

/** @return {string[]} everything present that is not on the list above. */
function leaksIn(glb) {
  const gltf = jsonChunkOf(glb);

  if (gltf === null) {
    return ['the proxy is not a readable glb'];
  }

  const found = [];
  const check = (object, allowed, where) => {
    for (const key of Object.keys(object ?? {})) {
      if (! allowed.includes(key)) {
        found.push(`${where}.${key}`);
      }
    }
  };

  check(gltf, ALLOWED.top, 'gltf');
  check(gltf.asset, ALLOWED.asset, 'asset');

  (gltf.nodes ?? []).forEach((node, i) => check(node, ALLOWED.node, `node[${i}]`));
  (gltf.materials ?? []).forEach((m, i) => check(m, ALLOWED.material, `material[${i}]`));

  (gltf.meshes ?? []).forEach((mesh, i) => {
    check(mesh, ALLOWED.mesh, `mesh[${i}]`);

    (mesh.primitives ?? []).forEach((primitive, p) => {
      check(primitive, ALLOWED.primitive, `mesh[${i}].primitive[${p}]`);
      check(primitive.attributes, ALLOWED.attribute, `mesh[${i}].primitive[${p}].attributes`);
    });
  });

  return [...new Set(found)];
}
let settle;
const finished = new Promise((done) => { settle = done; });

const ASSET_TYPES = {
  '.png': 'image/png', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg',
  '.webp': 'image/webp', '.bmp': 'image/bmp', '.tga': 'image/x-tga',
  '.mtl': 'text/plain', '.bin': 'application/octet-stream',
  '.gltf': 'model/gltf+json', '.glb': 'model/gltf-binary', '.obj': 'text/plain',
};

const ROUTES = {
  '/': [join(APP, 'proxy.html'), 'text/html'],
  '/proxy.html': [join(APP, 'proxy.html'), 'text/html'],
  '/job.json': [join(IN, 'job.json'), 'application/json'],
  '/model': [join(IN, 'model'), 'application/octet-stream'],
  '/simplify.js': [join(APP, 'simplify.js'), 'text/javascript'],
  '/shrinkTextures.js': [join(APP, 'shrinkTextures.js'), 'text/javascript'],
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

const server = createServer(async (request, response) => {
  const path = request.url.split('?')[0];

  if (request.method === 'POST') {
    const body = JSON.parse((await readBody(request)).toString() || '{}');

    if (path === '/done') {
      state.glb = Buffer.from(body.glb, 'base64');
      state.before = body.before ?? 0;
      state.after = body.after ?? 0;
      state.textures = body.textures ?? 0;
      state.baked = body.baked ?? null;
    } else if (path === '/failed') {
      state.failed = String(body.reason ?? 'The model could not be simplified.').slice(0, 300);
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

  if (typeof job.ratio !== 'number' || job.ratio < 0 || job.ratio > 1) {
    await writeResult({ status: 'failed', reason: 'No decimation ratio given.', retryable: false });

    return 2;
  }

  await new Promise((listening) => server.listen(PORT, '127.0.0.1', listening));

  const chrome = spawn(CHROME, [
    '--headless=new', '--disable-gpu',
    '--use-angle=swiftshader', '--enable-unsafe-swiftshader',
    '--no-sandbox', '--disable-dev-shm-usage',
    '--user-data-dir=/tmp/chrome',
    `http://127.0.0.1:${PORT}/proxy.html`,
  ], { stdio: ['ignore', 'ignore', 'pipe'] });

  let stderr = '';
  chrome.stderr.on('data', (chunk) => { stderr = (stderr + chunk).slice(-2000); });

  const startedAt = Date.now();
  const timedOut = await Promise.race([
    finished.then(() => false),
    new Promise((give) => setTimeout(() => give(true), TIMEOUT_MS)),
  ]);
  const seconds = Number(((Date.now() - startedAt) / 1000).toFixed(2));

  chrome.kill('SIGTERM');

  if (timedOut) {
    await writeResult({ status: 'failed', reason: 'Simplifying timed out.', retryable: true, seconds, stderr: stderr.slice(-600) });

    return 3;
  }

  if (state.failed !== null) {
    await writeResult({ status: 'failed', reason: state.failed, retryable: false, seconds });

    return 4;
  }

  if (state.glb === null || state.glb.length === 0) {
    await writeResult({ status: 'failed', reason: 'The simplifier produced nothing.', retryable: true, seconds });

    return 5;
  }

  const leaks = leaksIn(state.glb);

  if (leaks.length > 0) {
    await writeResult({
      status: 'failed',
      reason: 'The proxy carried more than a proxy may carry: '+leaks.slice(0, 12).join(', '),
      retryable: false,
      seconds,
    });

    return 6;
  }

  await writeFile(join(OUT, 'proxy.glb'), state.glb);
  await writeResult({
    status: 'ok',
    file: 'proxy.glb',
    bytes: state.glb.length,
    triangles: { before: state.before, after: state.after },
    texturesShrunk: state.textures,
    baked: state.baked,
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
