import { createSlice, createAsyncThunk } from '@reduxjs/toolkit';
import { postRequestWithToken } from '../api/axiosClient';

const initialState = {
  products: [],
  total: 0,
  status: 'idle',
  error: null,
};

export const cartSlice = createSlice({
  name: 'cart',
  initialState: initialState,
  reducers: {
    addToCart: (state, action) => {
      if (state.products.find((x) => x.id == action.payload.id)) {
        return {
          ...state,
        };
      }
      return {
        ...state,
        products: [...state.products, action.payload],
        total: parseFloat(state.total) + parseFloat(action.payload.price),
      };
    },
    clearCart: (state, action) => {
      return initialState;
    },
  },
  extraReducers(builder) {
    builder
      .addCase(checkoutCart.pending, (state, action) => {
        state.status = 'loading';
      })
      .addCase(checkoutCart.rejected, (state, action) => {
        state.status = 'failed';
        state.error = action.error.message;
      })
      .addCase(checkoutCart.fulfilled, (state, action) => {
        state.status = 'succeeded';
        state.error = null;
        state.products = [];
        state.total = 0;
      });
  },
});

export default cartSlice.reducer;

export const { addToCart, clearCart } = cartSlice.actions;

export const checkoutCart = createAsyncThunk(
  'cart/checkout',
  async (arg, { getState }) => {
    const state = getState();
    const token = state.auth.token;
    const products = state.cart.products.map((x) => {
      return { id: x.id };
    });

    let body = { products: products };

    const response = await postRequestWithToken('api/sales/buy', body, token);
  }
);
