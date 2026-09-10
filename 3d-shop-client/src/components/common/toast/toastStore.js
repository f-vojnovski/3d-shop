const AUTO_DISMISS_MS = 5000;

let toasts = [];
let nextId = 1;
const listeners = new Set();

const emit = () => {
  listeners.forEach((listener) => listener());
};

export const dismiss = (id) => {
  const remaining = toasts.filter((item) => item.id !== id);

  if (remaining.length !== toasts.length) {
    toasts = remaining;
    emit();
  }
};

const push = (tone, message) => {
  const id = nextId++;
  toasts = [...toasts, { id, tone, message: String(message) }];
  emit();

  setTimeout(() => dismiss(id), AUTO_DISMISS_MS);

  return id;
};

export const subscribe = (listener) => {
  listeners.add(listener);

  return () => listeners.delete(listener);
};

// Identity only changes when the list does, which useSyncExternalStore requires.
export const getToasts = () => toasts;

export const clearToasts = () => {
  toasts = [];
  emit();
};

export const toast = {
  success: (message) => push('success', message),
  error: (message) => push('error', message),
  info: (message) => push('info', message),
};
