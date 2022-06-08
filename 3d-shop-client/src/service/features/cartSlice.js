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
      return {
        ...state,
        products: [...state.products, action.payload],
        total: parseInt(state.total) + parseInt(action.payload.price),
      };
    },
  },
});

export default cartSlice.reducer;

export const { addToCart } = cartSlice.actions;
