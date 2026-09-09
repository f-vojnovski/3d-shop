import { createAsyncThunk, createSlice } from '@reduxjs/toolkit';
import { getRequest, getRequestWithToken } from '../api/axiosClient';

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
        state.perPage = action.payload.meta.per_page;
        state.total = action.payload.meta.total;
        state.pageCount = action.payload.meta.last_page;
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
        state.perPage = action.payload.meta.per_page;
        state.total = action.payload.meta.total;
        state.pageCount = action.payload.meta.last_page;
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
        state.perPage = action.payload.meta.per_page;
        state.total = action.payload.meta.total;
        state.pageCount = action.payload.meta.last_page;
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
    const response = await getRequest(`api/products?page=${pageNumber}`);
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
