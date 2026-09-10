import { combineReducers, configureStore } from '@reduxjs/toolkit';
import productsReducer from './features/productsSlice';
import productReducer from './features/productSlice';
import authReducer from './features/authSlice';
import cartReducer from './features/cartSlice';
import productUpload from './features/productUploadSlice';
import salesReducer from './features/salesSlice';
import uploadDraftReducer from './features/uploadDraftSlice';
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

const persistConfig = {
  key: 'root',
  version: 1,
  storage,
  whitelist: ['auth', 'cart'],
};

// `status` and `error` describe the last request, not the session.
const authPersistConfig = {
  key: 'auth',
  version: 1,
  storage,
  blacklist: ['status', 'error'],
};

const reducers = combineReducers({
  products: productsReducer,
  product: productReducer,
  productUpload: productUpload,
  uploadDraft: uploadDraftReducer,
  auth: persistReducer(authPersistConfig, authReducer),
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
        // The upload draft holds the chosen File objects until submit.
        ignoredPaths: ['uploadDraft.models', 'uploadDraft.thumbnail'],
        ignoredActionPaths: ['payload.file'],
      },
    }),
});

let persistor = persistStore(store);

export { store, persistor };
