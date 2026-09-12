import { createSelector, createSlice } from '@reduxjs/toolkit';

const initialState = {
  models: {},
  shots: {},
  active: null,
  thumbnails: [],
  sellerImages: [],
  previewMode: 'attested_stills',
  // What buyers aim at before they pay. The box is the older, safer answer.
  proxy: { mode: 'model', ratio: 0.1, method: 'careful' },
  converting: {},
  details: { name: '', description: '', price: '' },
  errors: {},
  nextShotId: 1,
};

const shotsOf = (state, format) => state.shots[format] ?? [];

export const uploadDraftSlice = createSlice({
  name: 'uploadDraft',
  initialState,
  reducers: {
    attachModel: (state, action) => {
      const { format, file, uri } = action.payload;

      state.models[format] = { file, uri, name: file.name };
      state.shots[format] = state.shots[format] ?? [];

      // Focus what was just attached: it has no angles yet, and publishing is
      // blocked until every attached format has one.
      state.active = format;
    },

    dropModel: (state, action) => {
      const format = action.payload;

      delete state.models[format];
      delete state.shots[format];
      delete state.converting[format];

      if (state.active === format) {
        state.active = Object.keys(state.models)[0] ?? null;
      }
    },

    setActive: (state, action) => {
      state.active = action.payload;
    },

    addShot: (state, action) => {
      const { format, camera, snapshot } = action.payload;

      state.shots[format] = [
        ...shotsOf(state, format),
        { id: state.nextShotId, camera, snapshot },
      ];
      state.nextShotId += 1;
    },

    retakeShot: (state, action) => {
      const { format, index, camera, snapshot } = action.payload;

      state.shots[format] = shotsOf(state, format).map((shot, at) =>
        at === index ? { ...shot, camera, snapshot } : shot
      );
    },

    removeShot: (state, action) => {
      const { format, index } = action.payload;

      state.shots[format] = shotsOf(state, format).filter((_, at) => at !== index);
    },

    moveShot: (state, action) => {
      const { format, index, by } = action.payload;
      const shots = [...shotsOf(state, format)];
      const to = index + by;

      if (to < 0 || to >= shots.length) {
        return;
      }

      [shots[index], shots[to]] = [shots[to], shots[index]];
      state.shots[format] = shots;
    },

    // A thumbnail taken from a shot is a copy of it, so the shot can be
    // retaken, reordered or dropped afterwards without disturbing the picture.
    addThumbnail: (state, action) => {
      state.thumbnails = [...state.thumbnails, action.payload];
    },

    removeThumbnail: (state, action) => {
      state.thumbnails = state.thumbnails.filter((_, at) => at !== action.payload);
    },

    addSellerImage: (state, action) => {
      state.sellerImages = [...state.sellerImages, action.payload];
    },

    removeSellerImage: (state, action) => {
      state.sellerImages = state.sellerImages.filter((_, at) => at !== action.payload);
    },

    // The two lists are not exclusive: the same picture can sell the listing
    // on a card and sit in the gallery underneath it.
    alsoAsThumbnail: (state, action) => {
      const image = state.sellerImages[action.payload];

      if (image && ! state.thumbnails.some((one) => one.uri === image.uri)) {
        state.thumbnails = [...state.thumbnails, image];
      }
    },

    alsoAsPreviewImage: (state, action) => {
      const thumbnail = state.thumbnails[action.payload];

      if (thumbnail && ! state.sellerImages.some((one) => one.uri === thumbnail.uri)) {
        state.sellerImages = [...state.sellerImages, thumbnail];
      }
    },

    convertingStarted: (state, action) => {
      state.converting[action.payload] = true;
    },

    convertedPreview: (state, action) => {
      const { format, previewUri } = action.payload;

      delete state.converting[format];

      if (state.models[format]) {
        state.models[format].previewUri = previewUri;
      }
    },

    conversionFailed: (state, action) => {
      delete state.converting[action.payload];
    },

    setDetails: (state, action) => {
      state.details = { ...state.details, ...action.payload };
    },

    setProxy: (state, action) => {
      state.proxy = { ...state.proxy, ...action.payload };
    },

    setPreviewMode: (state, action) => {
      state.previewMode = action.payload;
    },

    setErrors: (state, action) => {
      state.errors = action.payload;
    },

    resetDraft: () => initialState,
  },
});

export const {
  addSellerImage,
  attachModel,
  dropModel,
  setActive,
  addShot,
  retakeShot,
  removeShot,
  removeSellerImage,
  moveShot,
  addThumbnail,
  removeThumbnail,
  alsoAsThumbnail,
  alsoAsPreviewImage,
  convertingStarted,
  convertedPreview,
  conversionFailed,
  setDetails,
  setPreviewMode,
  setProxy,
  setErrors,
  resetDraft,
} = uploadDraftSlice.actions;

export default uploadDraftSlice.reducer;

const EMPTY = [];

export const selectAttachedFormats = createSelector(
  (state) => state.uploadDraft.models,
  (models) => Object.keys(models)
);

export const selectActiveShots = (state) =>
  state.uploadDraft.shots[state.uploadDraft.active] ?? EMPTY;
