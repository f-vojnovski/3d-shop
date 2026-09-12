import { combineReducers, configureStore } from '@reduxjs/toolkit';
import { registerUnauthorizedHandler } from './api/axiosClient';
import { sessionExpired } from './features/authSlice';
import { notify } from './features/toastSlice';
import productsReducer from './features/productsSlice';
import productReducer from './features/productSlice';
import authReducer from './features/authSlice';
import cartReducer from './features/cartSlice';
import customViewsReducer from './features/customViewSlice';
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

// `auth` and `cart` are deliberately absent: each persists itself below, and
// listing either here as well would store the whole composed slice under the
// root key and rehydrate it over the top, putting back the fields those configs
// exist to drop.
const persistConfig = {
  key: 'root',
  version: 1,
  storage,
  whitelist: [],
};

// `status` and `error` describe the last request, not the session: a failed
// sign-in would otherwise greet the user on every later visit.
const authPersistConfig = {
  key: 'auth',
  version: 1,
  storage,
  blacklist: ['status', 'error'],
};

// The cart survives a reload; the checkout that was in flight does not. A
// persisted `succeeded` order still holds the gateway's URL, and the checkout
// page redirects to it on sight — so restoring one sends the buyer back to a
// payment page every time they open their cart, with no way to reach it.
const cartPersistConfig = {
  key: 'cart',
  version: 1,
  storage,
  blacklist: ['status', 'error', 'order'],
};

const reducers = combineReducers({
  products: productsReducer,
  product: productReducer,
  productUpload: productUpload,
  uploadDraft: uploadDraftReducer,
  auth: persistReducer(authPersistConfig, authReducer),
  cart: persistReducer(cartPersistConfig, cartReducer),
  customViews: customViewsReducer,
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
