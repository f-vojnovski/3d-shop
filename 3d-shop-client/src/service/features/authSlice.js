import { createSlice, createAsyncThunk } from '@reduxjs/toolkit';
import { API_URL } from '../../consts';
import {
  getRequest,
  postRequest,
  postRequestWithToken,
  setAxiosBearerToken,
  setBearerToken,
} from '../api/axiosClient';
import checkIfUserConsentedToCookies from '../cookies/cookiesConsentChecker';
import cookies from '../cookies/cookiesWrapper';

const initialState = {
  token: null,
  user: null,
  status: 'idle',
  error: null,
};

export const authSlice = createSlice({
  name: 'auth',
  initialState: initialState,
  reducers: {},
  extraReducers(builder) {
    builder
      .addCase(postLoginData.pending, (state, action) => {
        state.status = 'loading';
      })
      .addCase(postLoginData.fulfilled, (state, action) => {
        state.status = 'succeeded';
        state.user = action.payload.user;
        state.token = action.payload.token;
      })
      .addCase(postLoginData.rejected, (state, action) => {
        state.status = 'failed';
        state.error = action.error.message;
      })
      .addCase(logoutUser.pending, (state, action) => {
        state.status = 'loading';
      })
      .addCase(logoutUser.fulfilled, (state, action) => {
        state.status = 'idle';
        state.token = null;
        state.user = null;
        state.error = null;
      })
      .addCase(logoutUser.rejected, (state, action) => {
        state.status = 'failed';
        state.error = action.error.message;
      });
  },
});

export default authSlice.reducer;

export const selectAuthStatus = (state) => state.status;

export const postLoginData = createAsyncThunk('auth/postLoginData', async (body) => {
  if (!checkIfUserConsentedToCookies()) {
    return;
  }

  if (!cookies.get('XSRF-TOKEN')) {
    await getRequest(`sanctum/csrf-cookie`, null, {
      withCredentials: true,
    });
  }

  const response = await postRequest('api/auth/login', body);
  return response.data;
});

export const logoutUser = createAsyncThunk('auth/logoutUser', async (token) => {
  const response = await postRequestWithToken('api/auth/logout', null, token);
  return response.data;
});
