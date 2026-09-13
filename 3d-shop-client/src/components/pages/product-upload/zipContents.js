import { FORMATS } from './formats';

/**
 * Reads the names inside a zip without unpacking it.
 *
 * A zip keeps a list of what it holds at its end, uncompressed, so the tail
 * answers which format an archive carries without a library and without reading
 * 50 MB of it.
 */

const EOCD = 0x06054b50;

const ENTRY = 0x02014b50;

// A zip may carry a comment of up to 64 KB after the record we are looking for.
const TAIL = 65557;

// Mac archives carry a second copy of every file in here. Never the model.
const IGNORED = /^__MACOSX\/|\/\._|^\._/;

const extensionOf = (name) => {
  const at = name.lastIndexOf('.');

  return at < 0 ? '' : name.slice(at).toLowerCase();
};

/** Where the list of contents starts, and how long it is. */
function directoryAt(view) {
  for (let at = view.byteLength - 22; at >= 0; at -= 1) {
    if (view.getUint32(at, true) !== EOCD) {
      continue;
    }

    const size = view.getUint32(at + 12, true);
    const offset = view.getUint32(at + 16, true);

    // 0xffffffff means the real numbers live in a zip64 record. Rare below the
    // 50 MB upload cap, and not worth guessing at.
    return size === 0xffffffff || offset === 0xffffffff ? null : { size, offset };
  }

  return null;
}

/** @return {string[]} every name the archive lists */
function namesIn(view) {
  const names = [];
  const bytes = new Uint8Array(view.buffer, view.byteOffset, view.byteLength);
  const text = new TextDecoder();
  let at = 0;

  while (at + 46 <= view.byteLength && view.getUint32(at, true) === ENTRY) {
    const nameLength = view.getUint16(at + 28, true);
    const extraLength = view.getUint16(at + 30, true);
    const commentLength = view.getUint16(at + 32, true);

    names.push(text.decode(bytes.subarray(at + 46, at + 46 + nameLength)));
    at += 46 + nameLength + extraLength + commentLength;
  }

  return names;
}

/**
 * Which format's field an archive belongs in, or null when it holds no model
 * this site sells. A zip with several is refused rather than guessed at.
 *
 * @return {Promise<{format: string|null, found: string[], names: string[]}>}
 */
export async function formatInsideZip(file) {
  try {
    const tail = new DataView(await file.slice(Math.max(0, file.size - TAIL)).arrayBuffer());
    const directory = directoryAt(tail);

    if (directory === null) {
      return { format: null, found: [], names: [] };
    }

    const listing = new DataView(
      await file.slice(directory.offset, directory.offset + directory.size).arrayBuffer()
    );

    const names = namesIn(listing).filter((name) => ! name.endsWith('/') && ! IGNORED.test(name));
    const found = [...new Set(
      names
        .map((name) => FORMATS.find((one) => one.extensions.includes(extensionOf(name)))?.key)
        .filter(Boolean)
    )];

    return { format: found.length === 1 ? found[0] : null, found, names };
  } catch {
    // A zip we cannot read is one the server will refuse anyway, and it says so
    // in words the seller can act on.
    return { format: null, found: [], names: [] };
  }
}
