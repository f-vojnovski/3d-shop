import { createSlice } from "@reduxjs/toolkit";

export const authSlice = createSlice({
  name: "auth",
  initialState: {
    apiToken: 0,
  },
  reducers: {
    loginUser: (state) => {
      state.apiToken = "test";
    },
    logoutUser: (state) => {
      state.apiToken = "";
    },
  },
});

export const { loginUser, logoutUser } = authSlice.actions;

export default authSlice.reducer;
