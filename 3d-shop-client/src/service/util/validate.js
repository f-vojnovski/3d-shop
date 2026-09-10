const text = (value) => String(value ?? '').trim();

export const required = (value, label) => (text(value) === '' ? `${label} is required.` : null);

export const email = (value) =>
  /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(text(value)) ? null : 'Enter a valid email address.';

export const minLength = (value, length, label) =>
  String(value ?? '').length < length ? `${label} must be at least ${length} characters.` : null;

export const same = (value, other, message) => (value === other ? null : message);

// Mirrors the API's numeric|min:0|max:999999.99.
export const price = (value) => {
  const amount = Number(text(value));

  if (text(value) === '' || Number.isNaN(amount)) {
    return 'Enter a price, for example 24.50.';
  }

  return amount < 0 || amount > 999999.99 ? 'Price must be between 0 and 999999.99.' : null;
};

export const firstErrors = (candidates) =>
  Object.fromEntries(Object.entries(candidates).filter(([, message]) => message));
