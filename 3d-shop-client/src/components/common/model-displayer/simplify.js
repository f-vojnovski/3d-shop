import { SimplifyModifier } from 'three/addons/modifiers/SimplifyModifier.js';

// Plain functions, no React: the render harness imports this too, so the proxy
// a seller approved is produced by the same code that produces the stored one.
const modifier = new SimplifyModifier();

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
 * Replaces each mesh's geometry with a simplified copy, in place. `keep` is the
 * share of detail to aim for: 1 leaves the model alone, 0 asks for as little as
 * the simplifier will give. Normals and UVs steer the error metric, so they
 * survive rather than being thrown away and recomputed.
 */
export const simplifyObject = async (object, keep) => {
  const meshes = [];

  object.traverse((node) => {
    if (node.isMesh && node.geometry) {
      meshes.push(node);
    }
  });

  for (const mesh of meshes) {
    const position = mesh.geometry.getAttribute('position');

    if (!position) {
      continue;
    }

    const remove = Math.round(position.count * (1 - keep));

    if (remove < 1) {
      continue;
    }

    mesh.geometry = await modifier.modify(mesh.geometry, remove);
  }

  return object;
};
