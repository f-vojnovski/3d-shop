import {
  createAsyncThunk,
  createSlice,
} from "@reduxjs/toolkit";
import { client } from "../api/client";

const initialState = {
  product: null,
  productUrl: null,
  productLoaded: false,
  urlLoaded: false,
  status: "idle",
  error: null,
};

export const productSlice = createSlice({
  name: "product",
  initialState,
  reducers: {
    resetProduct(state, action) {
      return initialState;
    }
  },
  extraReducers(builder) {
    builder
      .addCase(
        fetchProductById.pending,
        (state, action) => {
          state.status = "loading";
        }
      )
      .addCase(
        fetchProductById.fulfilled,
        (state, action) => {
          if (state.status !== "error") {
            state.productLoaded = true;
            state.status = state.urlLoaded
              ? "succeeded"
              : "loading";
            state.product = action.payload;
          }
        }
      )
      .addCase(
        fetchProductById.rejected,
        (state, action) => {
          state.status = "failed";
          state.productLoaded = "false";
          state.error = action.error.message;
        }
      )
      .addCase(fetchProductUrl.pending, (state, action) => {
        state.status = "loading";
      })
      .addCase(
        fetchProductUrl.fulfilled,
        (state, action) => {
          if (state.status !== "error") {
            state.urlLoaded = true;
            state.status = state.productLoaded
              ? "succeeded"
              : "loading";
            state.productUrl = action.payload;
          }
        }
      )
      .addCase(
        fetchProductUrl.rejected,
        (state, action) => {
          state.status = "failed";
          state.urlLoaded = false;
          state.error = action.error.message;
        }
      );
  },
});

export default productSlice.reducer;

export const selectProduct = (state) => state.product;

export const {resetProduct} = productSlice.actions;

export const fetchProductById = createAsyncThunk(
  "product/getById",
  async (productId) => {
    const response = await client.get(
      `http://127.0.0.1:8000/api/products/${productId}`
    );
    return response.data;
  }
);

export const fetchProductUrl = createAsyncThunk(
  "product/getUrlById",
  async (productId) => {
    const response = await client.get(
      `http://127.0.0.1:8000/api/products/getProductModelUrl/${productId}`
    );
    return response.data;
  }
);
