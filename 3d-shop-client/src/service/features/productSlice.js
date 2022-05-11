import {
  createAsyncThunk,
  createSlice,
} from "@reduxjs/toolkit";
import { client } from "../api/client";

const initialState = {
  product: null,
  status: "idle",
  error: null,
};

export const productSlice = createSlice({
  name: "product",
  initialState,
  reducers: {},
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
          state.status = "succeeded";
          state.product = action.payload;
        }
      )
      .addCase(
        fetchProductById.rejected,
        (state, action) => {
          state.status = "failed";
          state.error = action.error.message;
        }
      );
  },
});

export default productSlice.reducer;

export const selectProduct = (state) => state.product;

export const fetchProductById = createAsyncThunk(
  "product/getById",
  async (productId) => {
    const response = await client.get(
      `http://127.0.0.1:8000/api/products/${productId}`
    );
    return response.data;
  }
);
