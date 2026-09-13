import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import cookies from '../cookies/cookiesWrapper';

// The connector reads the client off the window; Echo does not import it.
window.Pusher = Pusher;

const port = Number(import.meta.env.VITE_REVERB_PORT ?? 8080);
const scheme = import.meta.env.VITE_REVERB_SCHEME ?? 'http';

export const createEcho = () =>
  new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST ?? 'localhost',
    wsPort: port,
    wssPort: port,
    forceTLS: scheme === 'https',
    enabledTransports: ['ws', 'wss'],
    // Same origin, so the session cookie goes on its own. Sanctum treats that
    // as stateful, which means this POST is CSRF-checked like any other.
    authEndpoint: '/broadcasting/auth',
    auth: {
      headers: {
        'X-XSRF-TOKEN': cookies.get('XSRF-TOKEN'),
        Accept: 'application/json',
      },
    },
  });
