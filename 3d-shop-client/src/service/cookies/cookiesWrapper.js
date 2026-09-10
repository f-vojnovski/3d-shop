const read = (name) =>
  document.cookie
    .split('; ')
    .map((pair) => pair.split('='))
    .find(([key]) => key === name)?.[1];

const cookies = {
  get: (name) => {
    const value = read(name);

    return value === undefined ? undefined : decodeURIComponent(value);
  },

  set: (name, value, days) => {
    document.cookie = [
      `${name}=${encodeURIComponent(value)}`,
      'path=/',
      `max-age=${days * 24 * 60 * 60}`,
      'samesite=lax',
    ].join('; ');
  },
};

export default cookies;
