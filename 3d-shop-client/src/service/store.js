import { configureStore } from '@reduxjs/toolkit';
import productsReducer from './features/productsSlice';
import productReducer from './features/productSlice';
import authReducer from './features/authSlice';
import cartReducer from './features/cartSlice';
import productUpload from './features/productUploadSlice';
import salesReducer from './features/salesSlice';
import {
  persistStore,
  persistReducer,
  FLUSH,
  REHYDRATE,
  PAUSE,
  PERSIST,
  PURGE,
  REGISTER,
} from 'redux-persist';
// lib/ is CommonJS; Vite's interop returns the namespace, not the storage object.
import storage from 'redux-persist/es/storage';
import { combineReducers } from 'redux';

const persistConfig = {
  key: 'root',
  version: 1,
  storage,
  whitelist: ['auth', 'cart'],
};

const reducers = combineReducers({
  products: productsReducer,
  product: productReducer,
  productUpload: productUpload,
  auth: authReducer,
  cart: cartReducer,
  sales: salesReducer,
});

const persistedReducer = persistReducer(persistConfig, reducers);

const store = configureStore({
  reducer: persistedReducer,
  middleware: (getDefaultMiddleware) =>
    getDefaultMiddleware({
      serializableCheck: {
        ignoredActions: [FLUSH, REHYDRATE, PAUSE, PERSIST, PURGE, REGISTER],
      },
    }),
});

let persistor = persistStore(store);

export { store, persistor };
