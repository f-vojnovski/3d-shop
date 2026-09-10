import { createAsyncThunk, createSlice } from '@reduxjs/toolkit';
import { postRequestWithToken } from '../api/axiosClient';

const initialState = {
  status: 'idle',
  error: null,
  fieldErrors: {},
  uploadedProduct: null,
};

export const productUploadSlice = createSlice({
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
        state.error = action.payload?.message ?? action.error.message;
        state.fieldErrors = action.payload?.errors ?? {};
      })
      .addCase(uploadProduct.fulfilled, (state, action) => {
        state.uploadedProduct = action.payload;
        state.status = 'succeeded';
      });
  },
});

export default productUploadSlice.reducer;

export const { clearUploadState } = productUploadSlice.actions;

export const uploadProduct = createAsyncThunk(
  'product/upload',
  async (body, { getState, rejectWithValue }) => {
    const token = getState().auth.token;

    try {
      const response = await postRequestWithToken('api/products', body, token);

      return response.data;
    } catch (failure) {
      // Without this the seller only ever sees "Request failed with status
      // code 422" and never the field the API objected to.
      const data = failure.response?.data;

      return rejectWithValue({
        message: data?.message ?? failure.message,
        errors: data?.errors ?? {},
      });
    }
  }
);
