import { createAsyncThunk, createSlice } from '@reduxjs/toolkit';
import { postRequestWithToken } from '../api/axiosClient';

const initialState = {
  status: 'idle',
  error: null,
  uploadedProduct: null,
};

export const productUploadSlcie = createSlice({
  name: 'productUpload',
  initialState: initialState,
  reducers: {
    clearUploadState: (state, action) => {
      return initialState;
    },
  },
  extraReducers(builder) {
    builder
      .addCase(uploadProduct.pending, (state, action) => {
        state.status = 'loading';
      })
      .addCase(uploadProduct.rejected, (state, action) => {
        state.status = 'failed';
        state.error = action.error.message;
      })
      .addCase(uploadProduct.fulfilled, (state, action) => {
        state.uploadedProduct = action.payload;
        state.status = 'succeeded';
      });
  },
});

export default productUploadSlcie.reducer;

export const { clearUploadState } = productUploadSlcie.actions;

export const uploadProduct = createAsyncThunk(
  'product/upload',
  async (body, { getState }) => {
    const state = getState();
    const token = state.auth.token;

    const response = await postRequestWithToken('api/products', body, token);
    return response.data;
  }
);
