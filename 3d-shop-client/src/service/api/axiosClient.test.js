import { endsTheSession } from './axiosClient';

const failure = (status, url) => ({ response: { status }, config: { url } });

describe('endsTheSession', () => {
  it('treats a 401 on an ordinary call as the session being over', () => {
    expect(endsTheSession(failure(401, '/api/current-user-products'))).toBe(true);
  });

  // Wrong credentials are not an expired session, and clearing state here
  // would wipe the error the login form is about to show.
  it('leaves a rejected sign-in to the login form', () => {
    expect(endsTheSession(failure(401, '/api/auth/login'))).toBe(false);
    expect(endsTheSession(failure(401, '/api/auth/register'))).toBe(false);
  });

  it('does not mistake a signed-out logout for wrong credentials', () => {
    expect(endsTheSession(failure(401, '/api/auth/logout'))).toBe(true);
  });

  it('ignores every other status', () => {
    expect(endsTheSession(failure(403, '/api/products/1/preview/obj'))).toBe(false);
    expect(endsTheSession(failure(422, '/api/products'))).toBe(false);
    expect(endsTheSession(failure(500, '/api/products'))).toBe(false);
  });

  it('survives an error that never reached the server', () => {
    expect(endsTheSession({ message: 'Network Error' })).toBe(false);
  });
});
