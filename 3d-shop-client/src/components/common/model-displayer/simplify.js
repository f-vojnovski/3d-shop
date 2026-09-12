import { BufferAttribute, BufferGeometry } from 'three';
import { MeshoptSimplifier } from 'three/addons/libs/meshopt_simplifier.module.js';
import { mergeVertices } from 'three/addons/utils/BufferGeometryUtils.js';

// Plain functions, no React: the render harness imports this too, so the proxy
// a seller approved is produced by the same code that produces the stored one.

/**
 * How hard the cutter is allowed to work. CAREFUL never removes a whole piece,
 * which on a model built from many separate parts can mean the slider barely
 * moves: almost every edge is a border and a border cannot be collapsed. PARTS
 * lets it drop small pieces outright, so the slider does what it says at the
 * cost of the smallest details.
 */
export const CAREFUL = 'careful';

export const PARTS = 'parts';

export const METHODS = [CAREFUL, PARTS];

const FLAGS = { [CAREFUL]: [], [PARTS]: ['Prune'] };

export const trianglesIn = (object) => {
  let total = 0;

  object.traverse((node) => {
    if (!node.isMesh || !node.geometry) {
      return;
    }

    const index = node.geometry.getIndex();
    const position = node.geometry.getAttribute('position');

    total += (index ? index.count : (position?.count ?? 0)) / 3;
  });

  return Math.round(total);
};

/**
 * Cuts one geometry down to `keep` of its triangles. This is what three's
 * SimplifyModifier does, reimplemented only because that one gives no way to
 * pass meshoptimizer a flag, and the flag is the whole difference above.
 */
const simplifyGeometry = async (input, keep, method) => {
  await MeshoptSimplifier.ready;

  const geometry = input.getIndex() === null ? mergeVertices(input) : input;
  const index = geometry.getIndex();
  const position = geometry.getAttribute('position');

  // meshoptimizer wants positions packed tight, whatever the buffer looked like.
  const positions = new Float32Array(position.count * 3);

  for (let i = 0; i < position.count; i += 1) {
    positions[i * 3] = position.getX(i);
    positions[i * 3 + 1] = position.getY(i);
    positions[i * 3 + 2] = position.getZ(i);
  }

  const target = Math.max(3, Math.floor((index.count * keep) / 3) * 3);

  // Normals and UVs steer the error metric, so collapses that would twist
  // shading or tear a texture seam cost more than ones that would not.
  const normal = geometry.getAttribute('normal');
  const uv = geometry.getAttribute('uv');
  const stride = (normal ? 3 : 0) + (uv ? 2 : 0);

  const extra = new Float32Array(position.count * stride);
  const weights = [];

  if (normal) weights.push(0.25, 0.25, 0.25);
  if (uv) weights.push(0.5, 0.5);

  for (let i = 0; i < position.count && stride > 0; i += 1) {
    let at = i * stride;

    if (normal) {
      extra[at] = normal.getX(i);
      extra[at + 1] = normal.getY(i);
      extra[at + 2] = normal.getZ(i);
      at += 3;
    }

    if (uv) {
      extra[at] = uv.getX(i);
      extra[at + 1] = uv.getY(i);
    }
  }

  const cut = (flags) => (stride > 0
    ? MeshoptSimplifier.simplifyWithAttributes(
      index.array, positions, 3, extra, stride, weights, null, target, 1, flags
    )
    : MeshoptSimplifier.simplify(index.array, positions, 3, target, 1, flags))[0];

  let indices = cut(FLAGS[method] ?? []);

  // Dropping pieces can take the last one: a mesh of parts that are all alike
  // either keeps them or loses the lot. An empty proxy is nothing to aim at, so
  // that mesh falls back to the cut that cannot delete anything.
  if (indices.length < 3 && method !== CAREFUL) {
    indices = cut(FLAGS[CAREFUL]);
  }

  const [remap, unique] = MeshoptSimplifier.compactMesh(indices);
  const simplified = new BufferGeometry();

  for (const name in geometry.attributes) {
    const attribute = geometry.getAttribute(name);
    const size = attribute.itemSize;
    const rebuilt = new BufferAttribute(
      new attribute.array.constructor(unique * size), size, attribute.normalized
    );

    for (let i = 0; i < remap.length; i += 1) {
      const to = remap[i];

      // 0xffffffff is a vertex the simplified index no longer mentions.
      if (to === 0xffffffff) {
        continue;
      }

      for (let k = 0; k < size; k += 1) {
        rebuilt.setComponent(to, k, attribute.getComponent(i, k));
      }
    }

    simplified.setAttribute(name, rebuilt);
  }

  simplified.setIndex(new BufferAttribute(indices, 1));

  return simplified;
};

/**
 * Replaces each mesh's geometry with a simplified copy, in place. `keep` is the
 * share of detail to aim for: 1 leaves the model alone, 0 asks for as little as
 * the simplifier will give.
 */
export const simplifyObject = async (object, keep, method = CAREFUL) => {
  const meshes = [];

  object.traverse((node) => {
    if (node.isMesh && node.geometry) {
      meshes.push(node);
    }
  });

  for (const mesh of meshes) {
    if (!mesh.geometry.getAttribute('position') || keep >= 1) {
      continue;
    }

    mesh.geometry = await simplifyGeometry(mesh.geometry, keep, method);
  }

  return object;
};
