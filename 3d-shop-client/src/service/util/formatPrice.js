// The only place minor units become a display string.
export function formatPrice(cents) {
  const value = Number(cents);

  if (!Number.isFinite(value)) {
    return '0.00';
  }

  return (value / 100).toFixed(2);
}
