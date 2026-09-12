import { CanvasTexture } from 'three';

// Plain functions, no React: the render harness imports this too, so the copy a
// seller approved on the slider carries the same textures as the stored one.

/**
 * Longest side a proxy texture may have. The aiming box is a few hundred pixels
 * wide, so this costs nothing to look at and leaves nothing worth lifting.
 */
export const PROXY_TEXTURE_MAX = 256;

/** Images arrive as ImageBitmap, <img> or <canvas>; anything else cannot be drawn. */
const drawable = (image) =>
  Boolean(image)
  && typeof image.width === 'number'
  && typeof image.height === 'number'
  && image.width > 0
  && image.height > 0
  && image.data === undefined;

const scaledCanvas = (image, max) => {
  const longest = Math.max(image.width, image.height);
  const ratio = max / longest;
  const canvas = document.createElement('canvas');

  canvas.width = Math.max(1, Math.round(image.width * ratio));
  canvas.height = Math.max(1, Math.round(image.height * ratio));

  const context = canvas.getContext('2d');
  context.imageSmoothingQuality = 'high';
  context.drawImage(image, 0, 0, canvas.width, canvas.height);

  return canvas;
};

/**
 * Replaces every texture on the object with a smaller copy, in place. Materials
 * are cloned first: three shares them across a cloned scene, so editing one
 * would shrink the full-size model this proxy is compared against.
 *
 * Slots are found by asking each material what it holds rather than by naming
 * them, so a three release that adds a new kind of map is covered too.
 */
export const shrinkTextures = (object, max = PROXY_TEXTURE_MAX) => {
  const already = new Map();
  let shrunk = 0;

  const smaller = (texture) => {
    if (already.has(texture)) {
      return already.get(texture);
    }

    const image = texture.image;

    if (!drawable(image) || Math.max(image.width, image.height) <= max) {
      already.set(texture, texture);

      return texture;
    }

    const copy = new CanvasTexture(scaledCanvas(image, max));

    copy.colorSpace = texture.colorSpace;
    copy.wrapS = texture.wrapS;
    copy.wrapT = texture.wrapT;
    copy.offset.copy(texture.offset);
    copy.repeat.copy(texture.repeat);
    copy.rotation = texture.rotation;
    copy.center.copy(texture.center);
    copy.flipY = texture.flipY;
    copy.channel = texture.channel;
    copy.needsUpdate = true;

    already.set(texture, copy);
    shrunk += 1;

    return copy;
  };

  object.traverse((node) => {
    if (!node.isMesh || !node.material) {
      return;
    }

    const materials = Array.isArray(node.material) ? node.material : [node.material];

    node.material = materials.map((material) => {
      const clone = material.clone();

      for (const key of Object.keys(clone)) {
        const value = clone[key];

        if (value && value.isTexture) {
          clone[key] = smaller(value);
        }
      }

      return clone;
    });

    if (!Array.isArray(node.material)) {
      return;
    }

    if (node.material.length === 1) {
      node.material = node.material[0];
    }
  });

  return shrunk;
};
