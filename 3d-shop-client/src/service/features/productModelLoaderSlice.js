import {
  createAsyncThunk,
  createSlice,
} from "@reduxjs/toolkit";
import { client } from "../api/client";

const initialState = {
  productUrl: null,
};

export const productModelLoaderSlice = createSlice({
  name: "productModelLoader",
  initialState,
  reducers: {}
});
