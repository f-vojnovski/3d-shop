import { createAsyncThunk, createSlice } from '@reduxjs/toolkit';
import { getRequest, postRequest } from '../api/axiosClient';

const initialState = {
  // Which product these belong to. A viewer can ask for a render on one product
  // and open another before it lands, and a render arriving for the first must
  // not appear under the second with a button offering to publish it there.
  productId: null,
  views: [],
  status: 'idle',
  requesting: false,
  error: null,
};

export const fetchCustomViews = createAsyncThunk(
  'customViews/fetch',
  async (productId, { getState }) => {
    const response = await getRequest(`/api/products/${productId}/views`);

    return response.data.views;
  },
);

export const requestCustomView = createAsyncThunk(
  'customViews/request',
  async ({ productId, format, pass, clip = null, camera }, { getState }) => {
    const response = await postRequest(`api/products/${productId}/views`, {
      format,
      pass,
      clip,
      camera,
    });

    return response.data;
  },
);

export const publishCustomView = createAsyncThunk(
  'customViews/publish',
  async ({ productId, viewId }, { getState }) => {
    const response = await postRequest(`api/products/${productId}/views/${viewId}/publish`, {});

    return response.data;
  },
);

const put = (state, view) => {
  const at = state.views.findIndex((one) => one.id === view.id);

  if (at === -1) {
    state.views.unshift(view);

    return;
  }

  state.views[at] = view;
};

export const customViewSlice = createSlice({
  name: 'customViews',
  initialState,
  reducers: {
    resetCustomViews() {
      return initialState;
    },
    // Reverb pushes the finished view; fetching on open covers a missed event.
    customViewDrawn(state, action) {
      const { productId } = action.payload;

      if (productId != null && Number(productId) !== Number(state.productId)) {
        return;
      }

      put(state, action.payload);
    },
  },
  extraReducers(builder) {
    builder
      .addCase(fetchCustomViews.pending, (state, action) => {
        state.status = 'loading';

        // Emptied here rather than on arrival, or the list on screen between
        // opening a product and its views landing is the last product's.
        if (Number(action.meta.arg) !== Number(state.productId)) {
          state.productId = action.meta.arg;
          state.views = [];
        }
      })
      .addCase(fetchCustomViews.fulfilled, (state, action) => {
        state.status = 'succeeded';
        state.views = Array.isArray(action.payload) ? action.payload : [];
      })
      .addCase(fetchCustomViews.rejected, (state, action) => {
        state.status = 'failed';
        state.error = action.error.message;
      })
      .addCase(requestCustomView.pending, (state) => {
        state.requesting = true;
        state.error = null;
      })
      .addCase(requestCustomView.fulfilled, (state, action) => {
        state.requesting = false;
        put(state, action.payload);
      })
      .addCase(requestCustomView.rejected, (state, action) => {
        state.requesting = false;
        state.error = action.error.message;
      });
  },
});

export const selectCustomViews = (state) => state.customViews.views;

export const { customViewDrawn, resetCustomViews } = customViewSlice.actions;

export default customViewSlice.reducer;
