import { createAsyncThunk, createSlice } from '@reduxjs/toolkit';
import { getRequestWithToken, postRequestWithToken } from '../api/axiosClient';

const initialState = {
  views: [],
  status: 'idle',
  requesting: false,
  error: null,
};

export const fetchCustomViews = createAsyncThunk(
  'customViews/fetch',
  async (productId, { getState }) => {
    const response = await getRequestWithToken(
      `/api/products/${productId}/views`,
      getState().auth.token,
    );

    return response.data.views;
  },
);

export const requestCustomView = createAsyncThunk(
  'customViews/request',
  async ({ productId, format, pass, camera }, { getState }) => {
    const response = await postRequestWithToken(
      `api/products/${productId}/views`,
      { format, pass, camera },
      getState().auth.token,
    );

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
      put(state, action.payload);
    },
  },
  extraReducers(builder) {
    builder
      .addCase(fetchCustomViews.pending, (state) => {
        state.status = 'loading';
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
