import { createAction, createSlice, createAsyncThunk, isAnyOf } from '@reduxjs/toolkit';
import { postRequestWithToken } from '../api/axiosClient';
import { logoutUser, sessionExpired } from './authSlice';

const endsASession = isAnyOf(logoutUser.fulfilled, logoutUser.rejected, sessionExpired);

/** Dispatched by the return page once the server reports the order paid. */
export const orderSettled = createAction('cart/orderSettled');

const initialState = {
  products: [],
  total: 0,
  status: 'idle',
  error: null,
  order: null,
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
        state.order = action.payload;

        // Only an order that is already settled empties the cart. One that is
        // waiting on a card keeps it, or a buyer who abandons the payment page
        // comes back to nothing.
        if (action.payload?.status === 'paid') {
          state.products = [];
          state.total = 0;
        }
      })
      .addCase(orderSettled, (state) => {
        state.products = [];
        state.total = 0;
        state.order = null;
      })
      // The cart is persisted, so without this the next person to use the
      // browser inherits the last one's.
      .addMatcher(endsASession, () => initialState);
  },
});

export default cartSlice.reducer;

export const { addToCart, removeFromCart, clearCart, clearCheckoutError } =
  cartSlice.actions;

/**
 * Opens a priced order. The server decides what it costs, and answers either
 * with somewhere to pay or with an order already settled — a free product, or
 * a build with payments switched off.
 */
export const checkoutCart = createAsyncThunk(
  'cart/checkout',
  async (arg, { getState }) => {
    const state = getState();
    const products = state.cart.products.map((product) => ({ id: product.id }));

    const response = await postRequestWithToken(
      'api/checkout/session',
      { products },
      state.auth.token
    );

    return response.data;
  }
);
