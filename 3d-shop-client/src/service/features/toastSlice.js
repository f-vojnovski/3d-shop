import { createSlice, nanoid } from '@reduxjs/toolkit';

const AUTO_DISMISS_MS = 5000;

const toastSlice = createSlice({
  name: 'toasts',
  initialState: { items: [] },
  reducers: {
    pushToast: {
      reducer: (state, action) => {
        state.items.push(action.payload);
      },
      prepare: (tone, message) => ({
        payload: { id: nanoid(), tone, message: String(message) },
      }),
    },
    dismissToast: (state, action) => {
      state.items = state.items.filter((item) => item.id !== action.payload);
    },
    clearToasts: (state) => {
      state.items = [];
    },
  },
});

export const { pushToast, dismissToast, clearToasts } = toastSlice.actions;

export const notify = (tone, message) => (dispatch) => {
  const action = pushToast(tone, message);

  dispatch(action);
  setTimeout(() => dispatch(dismissToast(action.payload.id)), AUTO_DISMISS_MS);

  return action.payload.id;
};

export const selectToasts = (state) => state.toasts.items;

export default toastSlice.reducer;
