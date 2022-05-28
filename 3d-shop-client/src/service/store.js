import { configureStore } from "@reduxjs/toolkit";
import productsReducer from "./features/productsSlice";
import productReducer from "./features/productSlice";
import authReducer from "./features/authSlice";

const store = configureStore({
  reducer: {
    products: productsReducer,
    product: productReducer,
    auth: authReducer
  },
});

export default store;
