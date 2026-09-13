import { createAsyncThunk, createSlice } from '@reduxjs/toolkit';
import { getRequest } from '../api/axiosClient';

const initialState = {
  sales: [],
  status: 'idle',
  error: null,
};

export const salesSlice = createSlice({
  name: 'sales',
  initialState: initialState,
  reducers: {},
  extraReducers(builder) {
    builder
      .addCase(fetchSales.pending, (state, action) => {
        state.status = 'loading';
      })
      .addCase(fetchSales.rejected, (state, action) => {
        state.status = 'failed';
        state.error = action.error.message;
      })
      .addCase(fetchSales.fulfilled, (state, action) => {
        state.status = 'succeeded';
        state.sales = action.payload;
      });
  },
});

export default salesSlice.reducer;

export const fetchSales = createAsyncThunk('sales/fetch', async () => {
  const response = await getRequest('api/sales');

  return response.data;
});
