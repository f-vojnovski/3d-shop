import { createSlice } from '@reduxjs/toolkit';

const initialState = {
  models: {},
  shots: {},
  active: null,
  thumbnail: null,
  previewMode: 'attested_stills',
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
      state.active = state.active ?? format;
    },

    dropModel: (state, action) => {
      const format = action.payload;

      delete state.models[format];
      delete state.shots[format];

      if (state.thumbnail?.from === format) {
        state.thumbnail = null;
      }

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

      if (state.thumbnail?.from === format && state.thumbnail.index === index) {
        state.thumbnail = null;
      }
    },

    removeShot: (state, action) => {
      const { format, index } = action.payload;

      state.shots[format] = shotsOf(state, format).filter((_, at) => at !== index);

      if (state.thumbnail?.from !== format) {
        return;
      }

      if (state.thumbnail.index === index) {
        state.thumbnail = null;
      } else if (state.thumbnail.index > index) {
        state.thumbnail = { ...state.thumbnail, index: state.thumbnail.index - 1 };
      }
    },

    // The thumbnail is a position in the roll, not an id, so it travels with
    // its shot rather than pointing at whatever lands in that slot.
    moveShot: (state, action) => {
      const { format, index, by } = action.payload;
      const shots = [...shotsOf(state, format)];
      const to = index + by;

      if (to < 0 || to >= shots.length) {
        return;
      }

      [shots[index], shots[to]] = [shots[to], shots[index]];
      state.shots[format] = shots;

      if (state.thumbnail?.from === format) {
        if (state.thumbnail.index === index) {
          state.thumbnail = { ...state.thumbnail, index: to };
        } else if (state.thumbnail.index === to) {
          state.thumbnail = { ...state.thumbnail, index };
        }
      }
    },

    setThumbnail: (state, action) => {
      state.thumbnail = action.payload;
    },

    setDetails: (state, action) => {
      state.details = { ...state.details, ...action.payload };
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
  attachModel,
  dropModel,
  setActive,
  addShot,
  retakeShot,
  removeShot,
  moveShot,
  setThumbnail,
  setDetails,
  setPreviewMode,
  setErrors,
  resetDraft,
} = uploadDraftSlice.actions;

export default uploadDraftSlice.reducer;

export const selectAttachedFormats = (state) => Object.keys(state.uploadDraft.models);
export const selectActiveShots = (state) =>
  state.uploadDraft.shots[state.uploadDraft.active] ?? [];
