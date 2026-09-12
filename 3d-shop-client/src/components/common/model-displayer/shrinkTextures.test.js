import { Group, Mesh, MeshStandardMaterial, SphereGeometry, SRGBColorSpace, Texture } from 'three';
import { PROXY_TEXTURE_MAX, shrinkTextures } from './shrinkTextures';

// jsdom has no rasteriser, so the drawing is stubbed: what is under test is
// which textures get picked and what replaces them, not the pixels.
const stubCanvas = () => {
  const created = [];

  vi.spyOn(document, 'createElement').mockImplementation((tag) => {
    if (tag !== 'canvas') {
      return { tagName: tag };
    }

    const canvas = {
      width: 0,
      height: 0,
      getContext: () => ({ drawImage: () => {}, imageSmoothingQuality: '' }),
    };

    created.push(canvas);

    return canvas;
  });

  return created;
};

const textureOf = (width, height) => {
  const texture = new Texture({ width, height });
  texture.colorSpace = SRGBColorSpace;

  return texture;
};

const meshWith = (properties) => {
  const material = new MeshStandardMaterial();
  Object.assign(material, properties);

  return new Mesh(new SphereGeometry(1, 8, 8), material);
};

describe('shrinkTextures', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('shrinks a texture larger than the cap down to it', () => {
    const canvases = stubCanvas();
    const mesh = meshWith({ map: textureOf(2048, 2048) });

    expect(shrinkTextures(mesh)).toBe(1);
    expect(canvases[0].width).toBe(PROXY_TEXTURE_MAX);
    expect(canvases[0].height).toBe(PROXY_TEXTURE_MAX);
  });

  it('keeps the shape of a texture that is not square', () => {
    const canvases = stubCanvas();

    shrinkTextures(meshWith({ map: textureOf(1024, 512) }));

    expect(canvases[0].width).toBe(PROXY_TEXTURE_MAX);
    expect(canvases[0].height).toBe(PROXY_TEXTURE_MAX / 2);
  });

  it('leaves a texture already small enough alone', () => {
    stubCanvas();
    const small = textureOf(128, 128);
    const mesh = meshWith({ map: small });

    expect(shrinkTextures(mesh)).toBe(0);
    expect(mesh.material.map).toBe(small);
  });

  /**
   * Cloning a scene shares its materials, so shrinking in place would also
   * shrink the full-size model the proxy is meant to be compared against.
   */
  it('does not touch the material it was given', () => {
    stubCanvas();
    const original = textureOf(2048, 2048);
    const mesh = meshWith({ map: original });
    const before = mesh.material;

    shrinkTextures(mesh);

    expect(mesh.material).not.toBe(before);
    expect(before.map).toBe(original);
    expect(before.map.image.width).toBe(2048);
  });

  /** Slots are found by asking the material, so a new kind of map is covered. */
  it('finds textures in every slot, not just the colour one', () => {
    stubCanvas();
    const mesh = meshWith({
      map: textureOf(1024, 1024),
      normalMap: textureOf(1024, 1024),
      roughnessMap: textureOf(1024, 1024),
      aoMap: textureOf(1024, 1024),
      emissiveMap: textureOf(1024, 1024),
    });

    expect(shrinkTextures(mesh)).toBe(5);
  });

  it('shrinks a shared texture once and reuses it', () => {
    const canvases = stubCanvas();
    const shared = textureOf(1024, 1024);
    const group = new Group();
    const a = meshWith({ map: shared });
    const b = meshWith({ map: shared });

    group.add(a, b);

    expect(shrinkTextures(group)).toBe(1);
    expect(canvases).toHaveLength(1);
    expect(a.material.map).toBe(b.material.map);
  });

  it('carries the settings a texture needs to still line up', () => {
    stubCanvas();
    const mesh = meshWith({ map: textureOf(1024, 1024) });

    mesh.material.map.repeat.set(2, 3);
    mesh.material.map.flipY = false;

    shrinkTextures(mesh);

    expect(mesh.material.map.colorSpace).toBe(SRGBColorSpace);
    expect(mesh.material.map.repeat.toArray()).toEqual([2, 3]);
    expect(mesh.material.map.flipY).toBe(false);
  });

  it('skips anything that cannot be drawn', () => {
    stubCanvas();
    const data = new Texture({ width: 4, height: 4, data: new Uint8Array(64) });

    expect(shrinkTextures(meshWith({ map: data }))).toBe(0);
  });
});
