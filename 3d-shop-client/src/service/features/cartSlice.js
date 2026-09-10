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
        total: state.total + action.payload.price_cents,
      };
    },
    removeFromCart: (state, action) => {
      const product = state.products.find((x) => x.id == action.payload);

      if (!product) {
        return state;
      }

      return {
        ...state,
        products: state.products.filter((x) => x.id != action.payload),
        total: state.total - product.price_cents,
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

export const { addToCart, removeFromCart, clearCart } = cartSlice.actions;

export const checkoutCart = createAsyncThunk(
  'cart/checkout',
  async (arg, { getState }) => {
    const state = getState();
    const token = state.auth.token;
    const products = state.cart.products.map((x) => {
      return { id: x.id };
    });

    const body = { products: products };

    await postRequestWithToken('api/sales/buy', body, token);
  }
);
