import axios from 'axios';

const axiosClient = axios.create();

// Root-relative. Callers pass paths both with and without a leading slash, and
// axios normalises either against this base into an absolute path.
axiosClient.defaults.baseURL = '/';

// Default timeout for requests in miliseconds
axiosClient.defaults.timeout = 15000;

axiosClient.defaults.withCredentials = true;

// Commented code for intercepting requests, if needed in futre
// axiosClient.interceptors.request.use((request) => {
//   console.log('Starting Request', JSON.stringify(request, null, 2));
//   return request;
// });

// axiosClient.interceptors.response.use((response) => {
//   console.log('Response:', JSON.stringify(response, null, 2));
//   return response;
// });

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
