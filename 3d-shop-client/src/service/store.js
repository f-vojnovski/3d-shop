import { combineReducers, configureStore } from '@reduxjs/toolkit';
import { registerUnauthorizedHandler } from './api/axiosClient';
import { sessionExpired } from './features/authSlice';
import { notify } from './features/toastSlice';
import productsReducer from './features/productsSlice';
import productReducer from './features/productSlice';
import authReducer from './features/authSlice';
import cartReducer from './features/cartSlice';
import productUpload from './features/productUploadSlice';
import salesReducer from './features/salesSlice';
import toastsReducer from './features/toastSlice';
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
  toasts: toastsReducer,
});

const persistedReducer = persistReducer(persistConfig, reducers);

const store = configureStore({
  reducer: persistedReducer,
  middleware: (getDefaultMiddleware) =>
    getDefaultMiddleware({
      serializableCheck: {
        ignoredActions: [FLUSH, REHYDRATE, PAUSE, PERSIST, PURGE, REGISTER],
        // The upload draft holds the chosen File objects until submit.
        ignoredPaths: [
          'uploadDraft.models',
          'uploadDraft.thumbnail',
          'uploadDraft.sellerImages',
        ],
        ignoredActionPaths: ['payload.file'],
      },
    }),
});

let persistor = persistStore(store);

// A token the API no longer accepts leaves the client signed in as far as it
// knows, so the session is ended here rather than on the next failed action.
registerUnauthorizedHandler(() => {
  if (store.getState().auth.token === null) {
    return;
  }

  store.dispatch(sessionExpired());
  store.dispatch(notify('error', 'Your session ended. Please sign in again.'));
});

export { store, persistor };
