import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * A failed sign-in used to come back on every later visit: the root config
 * persisted the whole composed `auth` slice, so its rehydrate put back the two
 * fields the nested config exists to drop.
 */
describe('persisted state', () => {
  beforeEach(() => {
    localStorage.clear();
    vi.resetModules();
  });

  const settle = () => new Promise((resolve) => setTimeout(resolve, 50));

  const persisted = () =>
    Object.entries(localStorage)
      .filter(([key]) => key.startsWith('persist:'))
      .map(([, value]) => value)
      .join('');

  it('never writes the last request outcome to storage', async () => {
    const { store, persistor } = await import('./store');
    const { postLoginData } = await import('./features/authSlice');

    store.dispatch({
      type: postLoginData.rejected.type,
      error: { message: 'Those credentials do not match our records.' },
    });
    await persistor.flush();
    await settle();

    expect(store.getState().auth.error).toBe('Those credentials do not match our records.');
    expect(persisted()).not.toContain('do not match our records');
    expect(persisted()).not.toContain('failed');
  });

  it('still keeps the session itself', async () => {
    const { store, persistor } = await import('./store');
    const { postLoginData } = await import('./features/authSlice');

    store.dispatch({
      type: postLoginData.fulfilled.type,
      payload: { user: { id: 1, name: 'seller' }, token: 'abc123' },
    });
    await persistor.flush();
    await settle();

    expect(persisted()).toContain('abc123');
  });
});
