import reducer, {
  addShot,
  attachModel,
  dropModel,
  moveShot,
  removeShot,
  resetDraft,
  retakeShot,
  addThumbnail,
  removeThumbnail,
  alsoAsThumbnail,
  alsoAsPreviewImage,
  addSellerImage,
  convertingStarted,
  convertedPreview,
  conversionFailed,
} from './uploadDraftSlice';

const file = (name) => ({ name });
const shot = (fov) => ({ camera: { fov }, snapshot: `data:image/jpeg;base64,${fov}` });

const withShots = (count) => {
  let state = reducer(undefined, attachModel({ format: 'obj', file: file('m.obj'), uri: 'u' }));

  for (let i = 0; i < count; i++) {
    state = reducer(state, addShot({ format: 'obj', ...shot(50 + i) }));
  }

  return state;
};

describe('upload draft', () => {
  it('keeps the converted copy beside the file that gets uploaded', () => {
    let state = reducer(undefined, attachModel({ format: 'fbx', file: file('t.fbx'), uri: 'original' }));

    expect(state.converting).toEqual({});

    state = reducer(state, convertingStarted('fbx'));
    expect(state.converting.fbx).toBe(true);

    state = reducer(state, convertedPreview({ format: 'fbx', previewUri: 'converted' }));

    expect(state.converting).toEqual({});
    expect(state.models.fbx.previewUri).toBe('converted');
    // The .fbx is still what the buyer downloads.
    expect(state.models.fbx.uri).toBe('original');
  });

  it('stops waiting when a conversion fails', () => {
    let state = reducer(undefined, attachModel({ format: 'fbx', file: file('t.fbx'), uri: 't' }));
    state = reducer(state, convertingStarted('fbx'));
    state = reducer(state, conversionFailed('fbx'));

    expect(state.converting).toEqual({});
    expect(state.models.fbx.previewUri).toBeUndefined();
  });

  it('forgets a conversion when the model is dropped', () => {
    let state = reducer(undefined, attachModel({ format: 'fbx', file: file('t.fbx'), uri: 't' }));
    state = reducer(state, convertingStarted('fbx'));
    state = reducer(state, dropModel('fbx'));

    expect(state.converting).toEqual({});
  });

  it('starts empty', () => {
    expect(reducer(undefined, { type: '@@INIT' })).toMatchObject({
      models: {},
      shots: {},
      active: null,
      thumbnails: [],
      sellerImages: [],
    });
  });

  // A format that was just attached has no angles, and publishing is blocked
  // until it has one, so it is the tab the seller needs to be on.
  it('makes the format that was just attached the active tab', () => {
    let state = reducer(undefined, attachModel({ format: 'obj', file: file('a.obj'), uri: 'a' }));
    expect(state.active).toBe('obj');

    state = reducer(state, attachModel({ format: 'gltf', file: file('b.glb'), uri: 'b' }));

    expect(Object.keys(state.models)).toEqual(['obj', 'gltf']);
    expect(state.active).toBe('gltf');
  });

  it('gives each format its own shots', () => {
    let state = withShots(2);
    state = reducer(state, attachModel({ format: 'gltf', file: file('b.glb'), uri: 'b' }));
    state = reducer(state, addShot({ format: 'gltf', ...shot(20) }));

    expect(state.shots.obj).toHaveLength(2);
    expect(state.shots.gltf).toHaveLength(1);
  });

  it('numbers shots uniquely so the roll can key on them', () => {
    const state = withShots(3);

    expect(new Set(state.shots.obj.map((one) => one.id)).size).toBe(3);
  });

  it('swaps neighbours when a shot moves and ignores the ends', () => {
    let state = withShots(3);
    const before = state.shots.obj.map((one) => one.id);

    state = reducer(state, moveShot({ format: 'obj', index: 0, by: 1 }));
    expect(state.shots.obj.map((one) => one.id)).toEqual([before[1], before[0], before[2]]);

    state = reducer(state, moveShot({ format: 'obj', index: 0, by: -1 }));
    expect(state.shots.obj.map((one) => one.id)).toEqual([before[1], before[0], before[2]]);
  });

  /**
   * A thumbnail is a copy, so the seller can frame a view purely to get the
   * picture and then clear the view away.
   */
  it('keeps a thumbnail taken from a shot after that shot is gone', () => {
    let state = withShots(2);
    state = reducer(state, addThumbnail({ file: file('t.jpg'), uri: 'taken' }));

    state = reducer(state, removeShot({ format: 'obj', index: 0 }));
    state = reducer(state, moveShot({ format: 'obj', index: 0, by: 1 }));

    expect(state.shots.obj).toHaveLength(1);
    expect(state.thumbnails.map((one) => one.uri)).toEqual(['taken']);
  });

  it('holds as many thumbnails as the seller adds', () => {
    let state = reducer(undefined, addThumbnail({ file: file('a.jpg'), uri: 'a' }));
    state = reducer(state, addThumbnail({ file: file('b.jpg'), uri: 'b' }));

    expect(state.thumbnails.map((one) => one.uri)).toEqual(['a', 'b']);

    state = reducer(state, removeThumbnail(0));

    expect(state.thumbnails.map((one) => one.uri)).toEqual(['b']);
  });

  /** The same picture can sell the card and sit in the gallery below it. */
  it('lets a picture be a thumbnail and a preview image at once', () => {
    let state = reducer(undefined, addSellerImage({ file: file('a.jpg'), uri: 'a' }));

    state = reducer(state, alsoAsThumbnail(0));

    expect(state.sellerImages.map((one) => one.uri)).toEqual(['a']);
    expect(state.thumbnails.map((one) => one.uri)).toEqual(['a']);
  });

  it('sends a thumbnail down to the preview images without losing it', () => {
    let state = reducer(undefined, addThumbnail({ file: file('b.jpg'), uri: 'b' }));

    state = reducer(state, alsoAsPreviewImage(0));

    expect(state.thumbnails.map((one) => one.uri)).toEqual(['b']);
    expect(state.sellerImages.map((one) => one.uri)).toEqual(['b']);
  });

  it('does not add the same picture to a list twice', () => {
    let state = reducer(undefined, addSellerImage({ file: file('a.jpg'), uri: 'a' }));

    state = reducer(state, alsoAsThumbnail(0));
    state = reducer(state, alsoAsThumbnail(0));

    expect(state.thumbnails).toHaveLength(1);
  });

  it('replaces a shot in place when it is retaken', () => {
    let state = withShots(2);
    const kept = state.shots.obj[1].id;

    state = reducer(state, retakeShot({ format: 'obj', index: 0, ...shot(99) }));

    expect(state.shots.obj[0].camera.fov).toBe(99);
    expect(state.shots.obj[1].id).toBe(kept);
  });

  it('hands the active tab to whatever is left when a format is removed', () => {
    let state = reducer(undefined, attachModel({ format: 'obj', file: file('a.obj'), uri: 'a' }));
    state = reducer(state, attachModel({ format: 'gltf', file: file('b.glb'), uri: 'b' }));

    state = reducer(state, dropModel('obj'));

    expect(state.active).toBe('gltf');
    expect(state.models.obj).toBeUndefined();
    expect(state.shots.obj).toBeUndefined();
  });

  it('goes back to the empty screen when the last format is removed', () => {
    const state = reducer(withShots(1), dropModel('obj'));

    expect(state.active).toBeNull();
    expect(Object.keys(state.models)).toEqual([]);
  });

  it('forgets everything after a successful publish', () => {
    let state = withShots(2);
    state = reducer(state, addThumbnail({ file: file('t.jpg'), uri: 't' }));

    expect(reducer(state, resetDraft())).toMatchObject({ models: {}, shots: {}, thumbnails: [] });
  });
});
