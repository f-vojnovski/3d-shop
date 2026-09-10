import reducer, {
  addShot,
  attachModel,
  dropModel,
  moveShot,
  removeShot,
  resetDraft,
  retakeShot,
  setThumbnail,
  toggleStandardViews,
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
  // Opt-in per format: a seller who frames their own shots should never have a
  // turntable appear underneath them.
  it('asks for standard views per format, off by default', () => {
    let state = reducer(undefined, attachModel({ format: 'obj', file: file('a.obj'), uri: 'a' }));
    state = reducer(state, attachModel({ format: 'gltf', file: file('b.glb'), uri: 'b' }));

    expect(state.standardViews).toEqual({});

    state = reducer(state, toggleStandardViews('obj'));

    expect(state.standardViews.obj).toBe(true);
    expect(state.standardViews.gltf).toBeFalsy();

    state = reducer(state, toggleStandardViews('obj'));

    expect(state.standardViews.obj).toBe(false);
  });

  it('starts empty', () => {
    expect(reducer(undefined, { type: '@@INIT' })).toMatchObject({
      models: {},
      shots: {},
      active: null,
      thumbnail: null,
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

  it('follows the thumbnail when its shot moves', () => {
    let state = withShots(3);
    state = reducer(state, setThumbnail({ file: file('t.jpg'), uri: 't', from: 'obj', index: 0 }));

    state = reducer(state, moveShot({ format: 'obj', index: 0, by: 1 }));

    expect(state.thumbnail.index).toBe(1);
  });

  it('keeps the thumbnail on its own picture when an earlier shot is removed', () => {
    let state = withShots(3);
    state = reducer(state, setThumbnail({ file: file('t.jpg'), uri: 't', from: 'obj', index: 2 }));
    const chosen = state.shots.obj[2].id;

    state = reducer(state, removeShot({ format: 'obj', index: 0 }));

    expect(state.shots.obj[state.thumbnail.index].id).toBe(chosen);
  });

  it('drops the thumbnail when the shot behind it is removed', () => {
    let state = withShots(2);
    state = reducer(state, setThumbnail({ file: file('t.jpg'), uri: 't', from: 'obj', index: 1 }));

    state = reducer(state, removeShot({ format: 'obj', index: 1 }));

    expect(state.shots.obj).toHaveLength(1);
    expect(state.thumbnail).toBeNull();
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
    state = reducer(state, setThumbnail({ file: file('t.jpg'), uri: 't', from: 'obj', index: 0 }));

    expect(reducer(state, resetDraft())).toMatchObject({ models: {}, shots: {}, thumbnail: null });
  });
});
