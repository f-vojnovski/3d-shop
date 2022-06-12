import { createAsyncThunk, createSlice } from '@reduxjs/toolkit';
import { postRequestWithToken } from '../api/axiosClient';

const initialState = {
  status: 'idle',
  error: null,
};

export const productUploadSlcie = createSlice({
  name: 'productUpload',
  initialState: initialState,
  reducers: {},
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
        state.status = 'succeeded';
      });
  },
});

export default productUploadSlcie.reducer;

export const uploadProduct = createAsyncThunk('product/upload', async (obj) => {
  console.log(obj.body);
  const response = await postRequestWithToken('api/products', obj.body, obj.token);
  return response.data;
});
