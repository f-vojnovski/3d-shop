import { createSlice } from '@reduxjs/toolkit';

const initialState = {
  products: [],
  total: 0,
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
});

export default cartSlice.reducer;

export const { addToCart, clearCart } = cartSlice.actions;
