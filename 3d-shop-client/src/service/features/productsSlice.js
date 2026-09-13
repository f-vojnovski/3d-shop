import { createAsyncThunk, createSlice } from '@reduxjs/toolkit';
import { getRequest } from '../api/axiosClient';

const initialState = {
  // The catalogue, your uploads and your purchases all write to this one list.
  // Whichever request is current wins, so a slow one cannot land your drafts in
  // the public catalogue after you have navigated away.
  latestRequest: null,
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
        state.latestRequest = action.meta.requestId;
      })
      .addCase(fetchProducts.fulfilled, (state, action) => {
        if (state.latestRequest !== action.meta.requestId) {
          return;
        }

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
        state.latestRequest = action.meta.requestId;
      })
      .addCase(fetchUploadedProductsForCurrentUser.fulfilled, (state, action) => {
        if (state.latestRequest !== action.meta.requestId) {
          return;
        }

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
        state.latestRequest = action.meta.requestId;
      })
      .addCase(fetchPurchasedProductsForCurrentUser.fulfilled, (state, action) => {
        if (state.latestRequest !== action.meta.requestId) {
          return;
        }

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
  async (pageNumber) => {
    const response = await getRequest(`api/current-user-products?page=${pageNumber}`);
    return response.data;
  }
);

export const fetchPurchasedProductsForCurrentUser = createAsyncThunk(
  'products/getPurchasedProductsForCurrentUser',
  async (pageNumber) => {
    const response = await getRequest(`api/owned-products?page=${pageNumber}`);
    return response.data;
  }
);
