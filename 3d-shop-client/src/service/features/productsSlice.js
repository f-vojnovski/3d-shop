import { createSlice } from "@reduxjs/toolkit";

const initialState = [
  { id: "1", name: "First Post!", description: "Hello!", price: 30.5 },
];

export const productsSlice = createSlice({
  name: "products",
  initialState,
  reducers: {},
});

export default productsSlice.reducer;
