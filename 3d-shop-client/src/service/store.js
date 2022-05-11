import { configureStore } from "@reduxjs/toolkit";
import productsReducer from "./features/productsSlice";
import productReducer from "./features/productSlice";

const store = configureStore({
  reducer: {
    products: productsReducer,
    product: productReducer,
  },
});

export default store;
