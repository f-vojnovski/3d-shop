// Adding a format is one entry here: the drop zone, tabs, validation and the
// upload payload all read this list.
export const FORMATS = [
  { key: 'obj', label: '.obj', extensions: ['.obj'], field: 'objModel' },
  { key: 'gltf', label: '.glb', extensions: ['.gltf', '.glb'], field: 'gltfModel' },
  { key: 'stl', label: '.stl', extensions: ['.stl'], field: 'stlModel' },
  // No browser loader, and the server renders a converted copy, so a
  // hand-framed camera would not point where the seller aimed it.
  { key: 'fbx', label: '.fbx', extensions: ['.fbx'], field: 'fbxModel', framing: false },
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

// Extensions plus MIME types: browsers filter the picker on one or the other.
export const acceptAttribute = [
  ...FORMATS.flatMap((format) => format.extensions),
  'model/gltf-binary',
  'model/gltf+json',
  ...IMAGE_EXTENSIONS,
  'image/*',
].join(',');

export const SUPPORTED_SUMMARY = `${FORMATS.flatMap((format) => format.extensions).join(', ')} or an image`;

export const labelFor = (key) => FORMATS.find((format) => format.key === key)?.label ?? key;

export const canFrame = (key) => FORMATS.find((format) => format.key === key)?.framing !== false;

export const megabytes = (bytes) => `${(bytes / 1024 / 1024).toFixed(1)} MB`;
