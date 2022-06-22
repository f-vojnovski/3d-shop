import { createAsyncThunk, createSlice } from '@reduxjs/toolkit';
import { getRequestWithToken } from '../api/axiosClient';
import { client } from '../api/client';

const initialState = {
  products: [],
  status: 'idle',
  error: null,
  currentPage: 1,
  perPage: null,
  total: null,
  pageCount: null,
};

export const productsSlice = createSlice({
  name: 'products',
  initialState,
  reducers: {
    clearProductsStatus(state, action) {
      state.status = 'idle';
    },
  },
  extraReducers(builder) {
    builder
      .addCase(fetchProducts.pending, (state, action) => {
        state.status = 'loading';
      })
      .addCase(fetchProducts.fulfilled, (state, action) => {
        state.status = 'succeeded';
        state.products = action.payload.data;
        state.currentPage = action.payload.current_page;
        state.perPage = action.payload.per_page;
        state.total = action.payload.total;
        state.pageCount = Math.ceil(state.total / state.perPage);
      })
      .addCase(fetchProducts.rejected, (state, action) => {
        state.status = 'failed';
        state.error = action.error.message;
      })
      .addCase(fetchUploadedProductsForCurrentUser.pending, (state, action) => {
        state.status = 'loading';
      })
      .addCase(fetchUploadedProductsForCurrentUser.fulfilled, (state, action) => {
        state.status = 'succeeded';
        state.products = action.payload.data;
        state.currentPage = action.payload.current_page;
        state.perPage = action.payload.per_page;
        state.total = action.payload.total;
        state.pageCount = Math.ceil(state.total / state.perPage);
      })
      .addCase(fetchUploadedProductsForCurrentUser.rejected, (state, action) => {
        state.status = 'failed';
        state.error = action.error.message;
      })
      .addCase(fetchPurchasedProductsForCurrentUser.pending, (state, action) => {
        state.status = 'loading';
      })
      .addCase(fetchPurchasedProductsForCurrentUser.fulfilled, (state, action) => {
        state.status = 'succeeded';
        state.products = action.payload.data;
        state.currentPage = action.payload.current_page;
        state.perPage = action.payload.per_page;
        state.total = action.payload.total;
        state.pageCount = Math.ceil(state.total / state.perPage);
      })
      .addCase(fetchPurchasedProductsForCurrentUser.rejected, (state, action) => {
        state.status = 'failed';
        state.error = action.error.message;
      });
  },
});

export default productsSlice.reducer;

export const selectAllProducts = (state) => state.products;

export const { clearProductsStatus } = productsSlice.actions;

export const fetchProducts = createAsyncThunk(
  'products/getProducts',
  async (pageNumber) => {
    const response = await client.get(
      `http://127.0.0.1:8000/api/products?page=${pageNumber}`
    );
    return response.data;
  }
);

export const fetchUploadedProductsForCurrentUser = createAsyncThunk(
  'products/getUploadedProductsForCurrentUser',
  async (pageNumber, { getState }) => {
    const state = getState();
    const token = state.auth.token;

    const response = await getRequestWithToken(
      `api/current-user-products?page=${pageNumber}`,
      token
    );
    return response.data;
  }
);

export const fetchPurchasedProductsForCurrentUser = createAsyncThunk(
  'products/getPurchasedProductsForCurrentUser',
  async (pageNumber, { getState }) => {
    const state = getState();
    const token = state.auth.token;

    const response = await getRequestWithToken(
      `api/owned-products?page=${pageNumber}`,
      token
    );
    return response.data;
  }
);
