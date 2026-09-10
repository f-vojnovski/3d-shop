// Relative, so the app is always same-origin: the Vite dev server proxies
// /api, /storage, /sanctum and /broadcasting to the API (see vite.config.js).
// This keeps the three.js loaders, which fetch model files over XHR, out of CORS.
export const API_URL = '';
export const CONSENT_COOKIE_NAME = 'userConsentToUsingCookies';
