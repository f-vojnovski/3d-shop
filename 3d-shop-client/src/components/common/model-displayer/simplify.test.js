import { BoxGeometry, Group, Mesh, MeshBasicMaterial, SphereGeometry } from 'three';
import { mergeGeometries } from 'three/addons/utils/BufferGeometryUtils.js';
import { CAREFUL, PARTS, simplifyObject, trianglesIn } from './simplify';

const sphere = (segments = 32) =>
  new Mesh(new SphereGeometry(1, segments, segments), new MeshBasicMaterial());

describe('simplify', () => {
  it('counts the triangles a model is made of', () => {
    const mesh = sphere(8);

    expect(trianglesIn(mesh)).toBe(mesh.geometry.getIndex().count / 3);
  });

  it('counts across every mesh in a scene', () => {
    const group = new Group();
    const a = sphere(8);
    const b = sphere(8);

    group.add(a, b);

    expect(trianglesIn(group)).toBe(trianglesIn(a) * 2);
  });

  it('cuts a model down to roughly the share asked for', async () => {
    const mesh = sphere(32);
    const before = trianglesIn(mesh);

    await simplifyObject(mesh, 0.25);

    const after = trianglesIn(mesh);

    expect(after).toBeLessThan(before);
    expect(after).toBeGreaterThan(0);
  });

  it('leaves the model alone when nothing is asked of it', async () => {
    const mesh = sphere(16);
    const before = trianglesIn(mesh);

    await simplifyObject(mesh, 1);

    expect(trianglesIn(mesh)).toBe(before);
  });

  /** Aiming needs a shape, not a point cloud: asking for nothing still leaves one. */
  it('still returns geometry at the far end of the slider', async () => {
    const mesh = sphere(32);

    await simplifyObject(mesh, 0);

    expect(trianglesIn(mesh)).toBeGreaterThan(0);
  });

  it('keeps the attributes a render needs', async () => {
    const mesh = sphere(32);

    await simplifyObject(mesh, 0.3);

    expect(mesh.geometry.getAttribute('position')).toBeTruthy();
    expect(mesh.geometry.getAttribute('normal')).toBeTruthy();
    expect(mesh.geometry.getAttribute('uv')).toBeTruthy();
  });
});

describe('how hard the cutter may work', () => {
  // A hard-surface model is many separate pieces: bolts, plates, panels. Nearly
  // every edge is a border, and a border cannot be collapsed.
  const pieces = (sizes) => {
    const parts = sizes.map((size, i) => {
      const part = new BoxGeometry(size, size, size);
      part.translate((i % 8) * 2, Math.floor(i / 8) * 2, 0);

      return part;
    });

    return new Mesh(mergeGeometries(parts), new MeshBasicMaterial());
  };

  const mixed = () => pieces([...Array(30).fill(0.05), ...Array(10).fill(0.9)]);

  it('barely moves a model of separate pieces when told to keep them all', async () => {
    const mesh = mixed();
    const before = trianglesIn(mesh);

    await simplifyObject(mesh, 0.25, CAREFUL);

    expect(trianglesIn(mesh) / before).toBeGreaterThan(0.9);
  });

  it('reaches the ratio when allowed to drop the small pieces', async () => {
    const mesh = mixed();
    const before = trianglesIn(mesh);

    await simplifyObject(mesh, 0.25, PARTS);

    expect(trianglesIn(mesh) / before).toBeLessThan(0.5);
  });

  /** All-alike pieces cross the threshold together, so dropping takes the lot. */
  it('keeps a model rather than dropping every piece of it', async () => {
    const mesh = pieces(Array(40).fill(0.2));

    await simplifyObject(mesh, 0.25, PARTS);

    expect(trianglesIn(mesh)).toBeGreaterThan(0);
  });

  it('treats a method it does not know as the careful one', async () => {
    const mesh = mixed();
    const before = trianglesIn(mesh);

    await simplifyObject(mesh, 0.25, 'whatever');

    expect(trianglesIn(mesh) / before).toBeGreaterThan(0.9);
  });
});
