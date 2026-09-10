// Adding a format is one entry here: the drop zone, tabs, validation and the
// upload payload all read this list.
export const FORMATS = [
  { key: 'obj', label: '.obj', extensions: ['.obj'], field: 'objModel' },
  { key: 'gltf', label: '.glb', extensions: ['.gltf', '.glb'], field: 'gltfModel' },
];

// Mirrors the API's max:51200 and max:5120 (kilobytes).
export const MAX_MODEL_BYTES = 51200 * 1024;
export const MAX_IMAGE_BYTES = 5120 * 1024;

export const IMAGE_EXTENSIONS = ['.png', '.jpg', '.jpeg', '.webp', '.gif'];

const extensionOf = (file) => {
  const at = file.name.lastIndexOf('.');

  return at < 0 ? '' : file.name.slice(at).toLowerCase();
};

export const formatOf = (file) => {
  const extension = extensionOf(file);

  return FORMATS.find((format) => format.extensions.includes(extension))?.key ?? null;
};

export const isImage = (file) => IMAGE_EXTENSIONS.includes(extensionOf(file));

export const acceptAttribute = [
  ...FORMATS.flatMap((format) => format.extensions),
  ...IMAGE_EXTENSIONS,
].join(',');

export const labelFor = (key) => FORMATS.find((format) => format.key === key)?.label ?? key;

export const megabytes = (bytes) => `${(bytes / 1024 / 1024).toFixed(1)} MB`;
