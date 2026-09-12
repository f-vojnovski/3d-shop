import { createAsyncThunk, createSlice, isAnyOf } from '@reduxjs/toolkit';
import {
  deleteRequestWithToken,
  getRequest,
  getRequestWithToken,
  postRequestWithToken,
} from '../api/axiosClient';

const initialState = {
  product: null,
  status: 'idle',
  error: null,
  replacing: false,
  publishing: false,
  savingThumbnails: false,
};

export const productSlice = createSlice({
  name: 'product',
  initialState,
  reducers: {
    resetProduct(state, action) {
      return initialState;
    },
  },
  extraReducers(builder) {
    builder
      .addCase(fetchProductById.pending, (state, action) => {
        state.status = 'loading';
      })
      .addCase(fetchProductById.fulfilled, (state, action) => {
        state.status = 'succeeded';
        state.product = action.payload;
      })
      .addCase(fetchProductById.rejected, (state, action) => {
        state.status = 'failed';
        state.productLoaded = 'false';
        state.error = action.error.message;
      })
      .addCase(replaceFile.pending, (state) => {
        state.replacing = true;
      })
      .addCase(replaceFile.fulfilled, (state, action) => {
        state.replacing = false;
        state.product = action.payload;
      })
      .addCase(replaceFile.rejected, (state, action) => {
        state.replacing = false;
        state.error = action.error.message;
      })
      .addCase(withdrawProduct.fulfilled, (state) => {
        if (state.product !== null) {
          state.product.unlisted = true;
          state.product.listing_status = 'withdrawn';
        }
      })
      .addCase(publishProduct.pending, (state) => {
        state.publishing = true;
      })
      .addCase(publishProduct.fulfilled, (state, action) => {
        state.publishing = false;
        state.product = action.payload;
      })
      .addCase(publishProduct.rejected, (state, action) => {
        state.publishing = false;
        state.error = action.error.message;
      })
      .addMatcher(isAnyOf(addThumbnails.pending, removeThumbnail.pending), (state) => {
        state.savingThumbnails = true;
      })
      .addMatcher(
        isAnyOf(addThumbnails.fulfilled, removeThumbnail.fulfilled),
        (state, action) => {
          state.savingThumbnails = false;
          state.product = action.payload;
        }
      )
      .addMatcher(
        isAnyOf(addThumbnails.rejected, removeThumbnail.rejected),
        (state, action) => {
          state.savingThumbnails = false;
          state.error = action.error.message;
        }
      );
  },
});

export default productSlice.reducer;

export const { resetProduct } = productSlice.actions;

export const fetchProductById = createAsyncThunk('/product/getById', async (productId, {getState}) => {
  const state = getState();
  const token = state.auth.token;

  let response;
  if (token) {
    response = await getRequestWithToken(`/api/products-authenticated/${productId}`, token);
  } else {
    response = await getRequest(`/api/products/${productId}`);
  }
  return response.data;
});

/** Puts a listing on sale, whether it has never been up or was taken down. */
export const publishProduct = createAsyncThunk(
  '/product/publish',
  async (productId, { getState }) => {
    const token = getState().auth.token;

    const response = await postRequestWithToken(
      `api/products/${productId}/publish`,
      {},
      token
    );

    return response.data;
  }
);

export const withdrawProduct = createAsyncThunk(
  '/product/withdraw',
  async (productId, { getState }) => {
    const token = getState().auth.token;

    await deleteRequestWithToken(`api/products/${productId}`, token);

    return productId;
  }
);

/**
 * Card pictures: files from the seller's machine, the ids of images the
 * product already has, or both. They come back shrunk once the job has run.
 */
export const addThumbnails = createAsyncThunk(
  '/product/addThumbnails',
  async ({ productId, files = [], from = [] }, { getState }) => {
    const token = getState().auth.token;
    const form = new FormData();

    files.forEach((file) => form.append('images[]', file));
    from.forEach((id) => form.append('from[]', id));

    const response = await postRequestWithToken(
      `api/products/${productId}/thumbnails`,
      form,
      token
    );

    return response.data;
  }
);

export const removeThumbnail = createAsyncThunk(
  '/product/removeThumbnail',
  async ({ productId, fileId }, { getState }) => {
    const token = getState().auth.token;

    const response = await deleteRequestWithToken(
      `api/products/${productId}/thumbnails/${fileId}`,
      token
    );

    return response.data;
  }
);
export const replaceFile = createAsyncThunk(
  '/product/replaceFile',
  async ({ productId, format, file, note }, { getState }) => {
    const token = getState().auth.token;
    const form = new FormData();

    form.append('format', format);
    form.append('model', file);

    if (note) {
      form.append('note', note);
    }

    const response = await postRequestWithToken(`api/products/${productId}/replace`, form, token);

    return response.data;
  }
);
