const UNITS = ['B', 'KB', 'MB', 'GB'];

export const formatBytes = (bytes) => {
  if (!Number.isFinite(bytes) || bytes < 0) {
    return '';
  }

  let value = bytes;
  let unit = 0;

  while (value >= 1024 && unit < UNITS.length - 1) {
    value /= 1024;
    unit++;
  }

  // A decimal on "3 KB" is noise; on "6.1 MB" it is two texture resolutions.
  return `${value.toFixed(unit >= 2 ? 1 : 0)} ${UNITS[unit]}`;
};
