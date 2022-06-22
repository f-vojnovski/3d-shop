import axios from 'axios';
import localStorage from 'redux-persist/es/storage';

const axiosClient = axios.create();

axiosClient.defaults.baseURL = 'http://localhost:8000';

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
  return axiosClient.get(`/${URL}`).then((response) => response);
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

export function setAxiosBearerToken(token) {
  localStorage.setItem('token', token);
  console.log(localStorage.getItem('token'));
  axiosClient.defaults.headers.common['Authorization'] = `Bearer ${token}`;
}
