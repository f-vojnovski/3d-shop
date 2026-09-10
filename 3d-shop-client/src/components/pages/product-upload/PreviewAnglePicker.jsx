
const MAX_ANGLES = 8;

const PreviewAnglePicker = ({
  previewMode,
  onPreviewModeChange,
  angles,
  onCapture,
  onRemove,
  canCapture,
}) => (
  <>
    <div className="row mt-3">
      <div className="col">
        <label className="form-label" htmlFor="preview-mode">
          How should buyers preview this model?
        </label>
        <select
          id="preview-mode"
          className="form-select"
          value={previewMode}
          onChange={(e) => onPreviewModeChange(e.target.value)}
        >
          <option value="interactive">
            Interactive &mdash; buyers rotate the real model in their browser
          </option>
          <option value="attested_stills">
            Images only &mdash; we render your chosen angles, the model stays private
          </option>
        </select>
      </div>
    </div>

    {previewMode === 'attested_stills' && (
      <div className="row mt-2">
        <div className="col">
          <p className="mb-2">
            Rotate the preview above to an angle you like, then capture it. We
            re-render each angle on the server from the file you upload, so the
            images buyers see are provably of this model.
          </p>

          <button
            type="button"
            className="btn btn-outline-primary"
            disabled={!canCapture || angles.length >= MAX_ANGLES}
            onClick={onCapture}
          >
            Capture this angle
          </button>

          {!canCapture && (
            <div className="form-text">Attach a model first.</div>
          )}

          {angles.length >= MAX_ANGLES && (
            <div className="form-text">
              That is the maximum of {MAX_ANGLES} angles.
            </div>
          )}

          {angles.length === 0 ? (
            <div className="form-text">
              No angles captured yet. Buyers will see only your thumbnail.
            </div>
          ) : (
            <ul className="list-group mt-2">
              {angles.map((angle, index) => (
                <li
                  key={index}
                  className="list-group-item d-flex justify-content-between align-items-center"
                >
                  <span>
                    Angle {index + 1}
                    <span className="text-muted ms-2">
                      {angle.position.map((n) => n.toFixed(1)).join(', ')}
                    </span>
                  </span>
                  <button
                    type="button"
                    className="btn btn-sm btn-outline-secondary"
                    onClick={() => onRemove(index)}
                  >
                    Remove
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>
    )}
  </>
);

export default PreviewAnglePicker;
