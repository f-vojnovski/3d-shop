import axios from 'axios';

const axiosClient = axios.create();

// Root-relative. Callers pass paths both with and without a leading slash, and
// axios normalises either against this base into an absolute path.
axiosClient.defaults.baseURL = '/';

// Default timeout for requests in miliseconds
axiosClient.defaults.timeout = 15000;

axiosClient.defaults.withCredentials = true;

// Framework text that names internals; never shown to a user.
const INTERNAL_MESSAGE = /^No query results for model/;

// Empty means "nothing useful from the server"; the component supplies context.
function displayableMessage(error) {
  const data = error.response?.data;

  if (data?.errors) {
    return Object.values(data.errors).flat().join(' ');
  }

  if (data?.message && !INTERNAL_MESSAGE.test(data.message)) {
    return data.message;
  }

  if (error.response?.status) {
    return `The server refused the request (${error.response.status}).`;
  }

  return error.message || 'Could not reach the server.';
}

// Set by the store, which cannot be imported here without a cycle.
let onUnauthorized = null;

export function registerUnauthorizedHandler(handler) {
  onUnauthorized = handler;
}

// A 401 from signing in means the credentials were wrong, not that a session
// ended, and clearing state there would fight the login form.
const CREDENTIAL_ROUTES = /\/?api\/auth\/(login|register)$/;

export function endsTheSession(error) {
  return (
    error.response?.status === 401 && !CREDENTIAL_ROUTES.test(error.config?.url ?? '')
  );
}

axiosClient.interceptors.response.use(
  (response) => response,
  (error) => {
    error.message = displayableMessage(error);

    if (onUnauthorized !== null && endsTheSession(error)) {
      onUnauthorized();
    }

    return Promise.reject(error);
  }
);

export function getRequest(URL) {
  return axiosClient.get(`${URL}`).then((response) => response);
}

export function getRequestWithToken(URL, token) {
  return axiosClient
    .get(`${URL}`, { headers: { Authorization: `Bearer ${token}` } })
    .then((response) => response);
}

export function postRequest(URL, payload) {
  return axiosClient.post(`/${URL}`, payload).then((response) => response);
}

export function postRequestWithToken(URL, payload, token) {
  return axiosClient
    .post(`/${URL}`, payload, { headers: { Authorization: `Bearer ${token}` } })
    .then((response) => response);
}

export function patchRequest(URL, payload) {
  return axiosClient.patch(`/${URL}`, payload).then((response) => response);
}

export function deleteRequest(URL) {
  return axiosClient.delete(`/${URL}`).then((response) => response);
}

export function deleteRequestWithToken(URL, token) {
  return axiosClient
    .delete(`/${URL}`, { headers: { Authorization: `Bearer ${token}` } })
    .then((response) => response);
}
