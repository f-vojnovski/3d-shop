import { formatInsideZip } from './zipContents';

/**
 * Builds a real zip, byte by byte, rather than mocking the reader. The point of
 * the module is that it understands the format, so a fake would test nothing.
 * Entries are stored uncompressed, which a zip is allowed to do.
 */
const zip = (names, { comment = '' } = {}) => {
  const encoder = new TextEncoder();
  const locals = [];
  const central = [];
  let offset = 0;

  for (const name of names) {
    const raw = encoder.encode(name);
    const body = encoder.encode('x');

    const local = new DataView(new ArrayBuffer(30 + raw.length + body.length));
    local.setUint32(0, 0x04034b50, true);
    local.setUint16(8, 0, true);
    local.setUint32(18, body.length, true);
    local.setUint32(22, body.length, true);
    local.setUint16(26, raw.length, true);
    const localBytes = new Uint8Array(local.buffer);
    localBytes.set(raw, 30);
    localBytes.set(body, 30 + raw.length);
    locals.push(localBytes);

    const entry = new DataView(new ArrayBuffer(46 + raw.length));
    entry.setUint32(0, 0x02014b50, true);
    entry.setUint32(20, body.length, true);
    entry.setUint32(24, body.length, true);
    entry.setUint16(28, raw.length, true);
    entry.setUint32(42, offset, true);
    const entryBytes = new Uint8Array(entry.buffer);
    entryBytes.set(raw, 46);
    central.push(entryBytes);

    offset += localBytes.length;
  }

  const directory = central.reduce((total, one) => total + one.length, 0);
  const tail = encoder.encode(comment);
  const end = new DataView(new ArrayBuffer(22 + tail.length));
  end.setUint32(0, 0x06054b50, true);
  end.setUint16(8, names.length, true);
  end.setUint16(10, names.length, true);
  end.setUint32(12, directory, true);
  end.setUint32(16, offset, true);
  end.setUint16(20, tail.length, true);
  const endBytes = new Uint8Array(end.buffer);
  endBytes.set(tail, 22);

  return new Blob([...locals, ...central, endBytes]);
};

describe('formatInsideZip', () => {
  it('finds the model an archive was packed around', async () => {
    const found = await formatInsideZip(zip([
      'car/car.obj',
      'car/car.mtl',
      'car/textures/body.png',
    ]));

    expect(found.format).toBe('obj');
  });

  it('reads every format the site sells', async () => {
    expect((await formatInsideZip(zip(['a.glb']))).format).toBe('gltf');
    expect((await formatInsideZip(zip(['a.gltf', 'a.bin']))).format).toBe('gltf');
    expect((await formatInsideZip(zip(['a.stl']))).format).toBe('stl');
    expect((await formatInsideZip(zip(['a.fbx']))).format).toBe('fbx');
  });

  it('reads a name whatever case it was written in', async () => {
    expect((await formatInsideZip(zip(['CAR.OBJ']))).format).toBe('obj');
  });

  /** Which field would it go in? There is no answer, so it does not guess. */
  it('refuses to choose when an archive holds two different models', async () => {
    const found = await formatInsideZip(zip(['car.obj', 'car.glb']));

    expect(found.format).toBeNull();
    expect(found.found).toHaveLength(2);
  });

  it('says nothing about an archive with no model in it', async () => {
    const found = await formatInsideZip(zip(['readme.txt', 'preview.png']));

    expect(found.format).toBeNull();
    expect(found.found).toEqual([]);
  });

  /** A Mac archive carries a shadow copy of every file; it is never the model. */
  it('ignores the copies a Mac puts in an archive', async () => {
    const found = await formatInsideZip(zip([
      '__MACOSX/car/._car.glb',
      'car/._car.obj',
      'car/car.obj',
    ]));

    expect(found.format).toBe('obj');
    expect(found.found).toEqual(['obj']);
  });

  it('ignores folder entries', async () => {
    expect((await formatInsideZip(zip(['car/', 'car/car.stl']))).format).toBe('stl');
  });

  /** A zip may carry up to 64 KB of comment after the part that is read. */
  it('still reads an archive that carries a comment', async () => {
    const found = await formatInsideZip(zip(['car.obj'], { comment: 'x'.repeat(5000) }));

    expect(found.format).toBe('obj');
  });

  it('answers quietly when handed something that is not a zip at all', async () => {
    const found = await formatInsideZip(new Blob(['not a zip, just words']));

    expect(found.format).toBeNull();
  });
});
