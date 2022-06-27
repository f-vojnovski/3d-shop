import { createSlice, createAsyncThunk } from '@reduxjs/toolkit';
import { getRequest, postRequest, postRequestWithToken } from '../api/axiosClient';
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
        state.status = 'idle';
        state.token = null;
        state.user = null;
        state.error = null;
        state.error = action.error.message;
      })
      .addCase(postRegisterData.pending, (state, action) => {
        state.status = 'loading';
      })
      .addCase(postRegisterData.fulfilled, (state, action) => {
        state.status = 'succeeded';
        state.user = action.payload.user;
        state.token = action.payload.token;
      })
      .addCase(postRegisterData.rejected, (state, action) => {
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

export const logoutUser = createAsyncThunk(
  'auth/logoutUser',
  async (arg, { getState }) => {
    const state = getState();
    const token = state.auth.token;

    const response = await postRequestWithToken('api/auth/logout', null, token);
    return response.data;
  }
);

export const postRegisterData = createAsyncThunk(
  'auth/postRegisterData',
  async (body) => {
    const response = await postRequest('api/auth/register', body);
    return response.data;
  }
);
