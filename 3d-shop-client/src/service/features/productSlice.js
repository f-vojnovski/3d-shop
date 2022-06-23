import { createAsyncThunk, createSlice } from '@reduxjs/toolkit';
import { getRequest, getRequestWithToken } from '../api/axiosClient';

const initialState = {
  product: null,
  status: 'idle',
  error: null,
};

export const productSlice = createSlice({
  name: 'product',
  initialState,
  reducers: {
    resetProduct(state, action) {
      return initialState;
    },
  },
  extraReducers(builder) {
    builder
      .addCase(fetchProductById.pending, (state, action) => {
        state.status = 'loading';
      })
      .addCase(fetchProductById.fulfilled, (state, action) => {
        if (state.status !== 'error') {
          state.status = 'succeeded';
          state.product = action.payload;
        }
      })
      .addCase(fetchProductById.rejected, (state, action) => {
        state.status = 'failed';
        state.productLoaded = 'false';
        state.error = action.error.message;
      });
  },
});

export default productSlice.reducer;

export const { resetProduct } = productSlice.actions;

export const fetchProductById = createAsyncThunk('product/getById', async (productId, {getState}) => {
  const state = getState();
  const token = state.auth.token;

  let response;
  if (token) {
    response = await getRequestWithToken(`/api/products-authenticated/${productId}`, token);
  } else {
    response = await getRequest(`/api/products/${productId}`);
  }
  return response.data;
});
