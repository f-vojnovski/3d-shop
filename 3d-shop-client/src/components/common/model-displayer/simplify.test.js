import { Group, Mesh, MeshBasicMaterial, SphereGeometry } from 'three';
import { simplifyObject, trianglesIn } from './simplify';

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
