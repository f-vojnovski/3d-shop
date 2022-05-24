import { createAsyncThunk, createSlice } from '@reduxjs/toolkit';
import { client } from '../api/client';

const initialState = {
  products: [],
  status: 'idle',
  error: null,
  currentPage: 1,
};

export const productsSlice = createSlice({
  name: 'products',
  initialState,
  reducers: {},
  extraReducers(builder) {
    builder
      .addCase(fetchProducts.pending, (state, action) => {
        state.status = 'loading';
      })
      .addCase(fetchProducts.fulfilled, (state, action) => {
        state.status = 'succeeded';
        state.products = state.products.concat(action.payload);
      })
      .addCase(fetchProducts.rejected, (state, action) => {
        state.status = 'failed';
        state.error = action.error.message;
      });
  },
});

export default productsSlice.reducer;

export const selectAllProducts = (state) => state.products;

export const fetchProducts = createAsyncThunk('products/getProducts', async () => {
  const response = await client.get('http://127.0.0.1:8000/api/products/');
  console.log(response);
  return response.data.data;
});
