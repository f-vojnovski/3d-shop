// Relative, so the app is always same-origin: in development the CRA dev server
// proxies /api, /storage and /sanctum to the API (see "proxy" in package.json).
// This keeps the three.js loaders, which fetch model files over XHR, out of CORS.
export const API_URL = '';
export const CONSENT_COOKIE_NAME = 'userConsentToUsingCookies';
