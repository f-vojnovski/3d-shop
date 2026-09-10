import { createSlice, createAsyncThunk, isAnyOf } from '@reduxjs/toolkit';
import { postRequestWithToken } from '../api/axiosClient';
import { logoutUser, sessionExpired } from './authSlice';

const endsASession = isAnyOf(logoutUser.fulfilled, logoutUser.rejected, sessionExpired);

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

    clearCheckoutError: (state) => {
      state.status = 'idle';
      state.error = null;
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
      })
      // The cart is persisted, so without this the next person to use the
      // browser inherits the last one's.
      .addMatcher(endsASession, () => initialState);
  },
});

export default cartSlice.reducer;

export const { addToCart, removeFromCart, clearCart, clearCheckoutError } =
  cartSlice.actions;

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
