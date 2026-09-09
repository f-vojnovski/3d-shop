import axios from 'axios';

const axiosClient = axios.create();

// Root-relative. Callers pass paths both with and without a leading slash, and
// axios normalises either against this base into an absolute path.
axiosClient.defaults.baseURL = '/';

// Default timeout for requests in miliseconds
axiosClient.defaults.timeout = 15000;

axiosClient.defaults.withCredentials = true;

// Framework-generated text that names internals; never shown to a user.
const INTERNAL_MESSAGE = /^No query results for model/;

// Rewrites error.message into something displayable, because that is what the
// slices store and the components render. An empty string means "no useful
// server message", which lets the calling component supply the context.
function displayableMessage(error) {
  const data = error.response?.data;

  if (data?.errors) {
    return Object.values(data.errors).flat().join(' ');
  }

  if (data?.message && !INTERNAL_MESSAGE.test(data.message)) {
    return data.message;
  }

  return '';
}

axiosClient.interceptors.response.use(
  (response) => response,
  (error) => {
    error.message = displayableMessage(error);
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
