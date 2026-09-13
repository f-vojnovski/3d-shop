import { createSlice, createAsyncThunk } from '@reduxjs/toolkit';
import { getRequest, postRequest } from '../api/axiosClient';
import checkIfUserConsentedToCookies from '../cookies/cookiesConsentChecker';
import cookies from '../cookies/cookiesWrapper';

// No token: the session is an httpOnly cookie the browser sends on its own.
const initialState = {
  user: null,
  status: 'idle',
  error: null,
};

export const authSlice = createSlice({
  name: 'auth',
  initialState: initialState,
  reducers: {
    sessionExpired: (state) => {
      state.user = null;
      state.status = 'idle';
      state.error = null;
    },
  },
  extraReducers(builder) {
    builder
      .addCase(postLoginData.pending, (state, action) => {
        state.status = 'loading';
      })
      .addCase(postLoginData.fulfilled, (state, action) => {
        state.status = 'succeeded';
        state.user = action.payload.user;
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
        state.user = null;
        state.error = null;
      })
      .addCase(logoutUser.rejected, (state, action) => {
        state.status = 'idle';
        state.user = null;
        state.error = null;
      })
      .addCase(postRegisterData.pending, (state, action) => {
        state.status = 'loading';
      })
      .addCase(postRegisterData.fulfilled, (state, action) => {
        state.status = 'succeeded';
        state.user = action.payload.user;
      })
      .addCase(postRegisterData.rejected, (state, action) => {
        state.status = 'failed';
        state.error = action.error.message;
      });
  },
});

export default authSlice.reducer;

export const postLoginData = createAsyncThunk('auth/postLoginData', async (body) => {
  if (!checkIfUserConsentedToCookies()) {
    throw new Error('Please accept cookies before signing in.');
  }

  await csrfCookie();

  const response = await postRequest('api/auth/login', body);
  return response.data;
});

/** Laravel refuses a write without this, and registering is a write now too. */
async function csrfCookie() {
  if (!cookies.get('XSRF-TOKEN')) {
    await getRequest('sanctum/csrf-cookie', null, { withCredentials: true });
  }
}

export const { sessionExpired } = authSlice.actions;

export const logoutUser = createAsyncThunk(
  'auth/logoutUser',
  async () => {
    const response = await postRequest('api/auth/logout', null);
    return response.data;
  }
);

export const postRegisterData = createAsyncThunk(
  'auth/postRegisterData',
  async (body) => {
    await csrfCookie();

    const response = await postRequest('api/auth/register', body);
    return response.data;
  }
);
