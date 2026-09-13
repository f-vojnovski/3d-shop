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

  // Polled rather than slept on: redux-persist writes on its own schedule and
  // a fixed wait is a test that fails when the machine is busy.
  const settled = async (predicate) => {
    for (let attempt = 0; attempt < 100; attempt += 1) {
      if (predicate()) return;
      await new Promise((resolve) => setTimeout(resolve, 20));
    }

    // Giving up quietly here made the next assertion fail with a message about
    // the wrong thing, on a busy machine, once in a few dozen runs.
    throw new Error('redux-persist did not write within two seconds.');
  };

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
    await settled(() => persisted().includes('user'));

    expect(store.getState().auth.error).toBe('Those credentials do not match our records.');
    expect(persisted()).not.toContain('do not match our records');
    expect(persisted()).not.toContain('failed');
  });

  /**
   * Storage may remember who you were, never anything that proves it.
   */
  it('remembers who you are without storing anything that signs you in', async () => {
    const { store, persistor } = await import('./store');
    const { postLoginData } = await import('./features/authSlice');

    store.dispatch({
      type: postLoginData.fulfilled.type,
      payload: { user: { id: 1, name: 'seller' }, token: 'abc123' },
    });
    await persistor.flush();
    await settled(() => persisted().includes('seller'));

    expect(persisted()).toContain('seller');
    expect(persisted()).not.toContain('abc123');
    expect(persisted()).not.toContain('token');
  });
});
