import { createSlice, createAsyncThunk } from '@reduxjs/toolkit';
import axios from 'axios';
import { API_URL } from '../../consts';
import { postRequest } from '../api/axiosClient';
import { client } from '../api/client';

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
      });
  },
});

export default authSlice.reducer;

export const selectUser = (state) => state.user;

export const selectToken = (state) => state.token;

export const selectAuthStatus = (state) => state.status;

export const postLoginData = createAsyncThunk('auth/postLoginData', async (body) => {
  const response = await postRequest('api/auth/login', body);
  return response.data;
});

export const getSanctumCookie = createAsyncThunk('auth/getSanctumCookie', async () => {
  const response = await client.get(`${API_URL}/sanctum/csrf-cookie`);
  return response.data;
});
