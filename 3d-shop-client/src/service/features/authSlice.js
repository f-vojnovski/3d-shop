import { createSlice } from '@reduxjs/toolkit';
import { API_URL } from '../../consts';

const initialState = {
  token: null,
  user: null,
  state: 'idle',
};

export const authSlice = createSlice({
  name: 'auth',
  initialState: initialState,
  reducers: {
    loginUser: (state) => {
      state.token = 'test';
    },
    logoutUser: (state) => {
      state.token = '';
    },
  },
});

export default authSlice.reducer;

export const selectUser = (state) => state.user;

export const selectToken = (state) => state.token;

export const fetchProductUrl = createAsyncThunk(
  'product/getUrlById',
  async (name, password) => {
    body = {
      name: name,
      password: password,
    };

    const response = await client.post(`${API_URL}/api/login`, body);
    return response.data;
  }
);
