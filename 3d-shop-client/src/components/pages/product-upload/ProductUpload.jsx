import { useCallback, useEffect, useRef } from 'react';
import { useDispatch, useSelector } from 'react-redux';
import { useNavigate } from 'react-router-dom';
import { clearUploadState, uploadProduct } from '../../../service/features/productUploadSlice';
import {
  addShot,
  attachModel,
  dropModel,
  moveShot,
  removeShot,
  resetDraft,
  retakeShot,
  selectActiveShots,
  selectAttachedFormats,
  setActive,
  setDetails,
  setErrors,
  setPreviewMode,
  setThumbnail,
} from '../../../service/features/uploadDraftSlice';
import { clearModelCache } from '../../common/model-displayer/modelCache';
import { fileToDataUri } from '../../../service/util/fileToDataUri';
import { firstErrors, price as validatePrice, required } from '../../../service/util/validate';
import { toast } from '../../common/toast/toastStore';
import SubmitButton from '../../common/submit-button/SubmitButton';
import CameraRoll from './CameraRoll';
import CaptureStage from './CaptureStage';
import DropZone from './DropZone';
import {
  FORMATS,
  MAX_IMAGE_BYTES,
  MAX_MODEL_BYTES,
  SUPPORTED_SUMMARY,
  formatOf,
  isImage,
  labelFor,
  megabytes,
} from './formats';
import styles from './ProductUpload.module.css';

const ATTESTED = 'attested_stills';
const INTERACTIVE = 'interactive';

const dataUriToFile = async (uri, name) => {
  const blob = await (await fetch(uri)).blob();

  return new File([blob], name, { type: blob.type });
};

const ProductUploadPage = () => {
  const { active, details, errors, models, previewMode, shots, thumbnail } = useSelector(
    (state) => state.uploadDraft
  );
  const attached = useSelector(selectAttachedFormats);
  const activeShots = useSelector(selectActiveShots);

  const uploaded = useSelector((state) => state.productUpload.uploadedProduct);
  const status = useSelector((state) => state.productUpload.status);
  const error = useSelector((state) => state.productUpload.error);
  const fieldErrors = useSelector((state) => state.productUpload.fieldErrors);

  // A handle into the live canvas, not state.
  const probe = useRef(null);

  const dispatch = useDispatch();
  const navigate = useNavigate();

  useEffect(() => {
    if (status === 'succeeded' && uploaded) {
      Object.values(models).forEach((model) => {
        URL.revokeObjectURL(model.uri);
        clearModelCache(model.uri);
      });

      dispatch(clearUploadState());
      dispatch(resetDraft());
      toast.success('Your product is live.');
      navigate(`/product/${uploaded.id}`);
    }
  }, [status, uploaded, models, dispatch, navigate]);

  useEffect(() => {
    if (status === 'failed') {
      toast.error(error || 'Upload failed. Check the files and try again.');
    }
  }, [status, error]);

  const accept = useCallback(async (files) => {
    for (const file of files) {
      const format = formatOf(file);

      if (format) {
        if (file.size > MAX_MODEL_BYTES) {
          toast.error(
            `${file.name} is ${megabytes(file.size)}; the limit is ${megabytes(MAX_MODEL_BYTES)}.`
          );
          continue;
        }

        dispatch(attachModel({ format, file, uri: URL.createObjectURL(file) }));
        continue;
      }

      if (isImage(file)) {
        if (file.size > MAX_IMAGE_BYTES) {
          toast.error(
            `${file.name} is ${megabytes(file.size)}; the limit is ${megabytes(MAX_IMAGE_BYTES)}.`
          );
          continue;
        }

        dispatch(setThumbnail({ file, uri: await fileToDataUri(file), from: 'upload' }));
        continue;
      }

      toast.error(`${file.name} is not supported. Use ${SUPPORTED_SUMMARY}.`);
    }
  }, [dispatch]);

  // A drop that misses the zone would otherwise be handled by the browser,
  // which opens the file and looks like the page silently ignoring it.
  useEffect(() => {
    const swallow = (event) => event.preventDefault();

    const anywhere = (event) => {
      event.preventDefault();

      if (event.dataTransfer?.files?.length) {
        accept(Array.from(event.dataTransfer.files));
      }
    };

    window.addEventListener('dragover', swallow);
    window.addEventListener('drop', anywhere);

    return () => {
      window.removeEventListener('dragover', swallow);
      window.removeEventListener('drop', anywhere);
    };
  }, [accept]);

  const releaseModel = (format) => {
    const url = models[format]?.uri;

    if (url) {
      URL.revokeObjectURL(url);
      clearModelCache(url);
    }
  };

  const capture = () => {
    const taken = probe.current?.();

    if (taken) {
      dispatch(addShot({ format: active, ...taken }));
    }
  };

  const retake = (index) => {
    const taken = probe.current?.();

    if (taken) {
      dispatch(retakeShot({ format: active, index, ...taken }));
    }
  };

  const useAsThumbnail = async (index) => {
    const shot = activeShots[index];

    dispatch(
      setThumbnail({
        file: await dataUriToFile(shot.snapshot, `${active}-thumbnail.jpg`),
        uri: shot.snapshot,
        from: active,
        index,
      })
    );
  };

  const submit = () => {
    const missing = previewMode === ATTESTED
      ? attached.filter((format) => (shots[format] ?? []).length === 0)
      : [];

    const found = firstErrors({
      name: required(details.name, 'A name'),
      price: validatePrice(details.price),
      thumbnail: thumbnail ? null : 'Pick a thumbnail: capture one or drop an image.',
      angles: missing.length === 0
        ? null
        : `Capture at least one view of ${missing.map(labelFor).join(' and ')}.`,
    });

    dispatch(setErrors(found));

    if (Object.keys(found).length > 0) {
      return;
    }

    const form = new FormData();

    FORMATS.filter((format) => models[format.key]).forEach((format) => {
      form.append(format.field, models[format.key].file);
    });

    form.append('thumbnail', thumbnail.file);
    form.append('name', details.name);
    form.append('description', details.description);
    form.append('price', details.price);
    form.append('preview_mode', previewMode);

    if (previewMode === ATTESTED) {
      // Only the camera numbers travel; the roll images stay in the browser.
      form.append(
        'preview_angles',
        JSON.stringify(
          Object.fromEntries(
            attached.map((format) => [format, (shots[format] ?? []).map((shot) => shot.camera)])
          )
        )
      );
    }

    dispatch(uploadProduct(form));
  };

  if (attached.length === 0) {
    return (
      <div className={styles.page}>
        <DropZone onFiles={accept} />
      </div>
    );
  }

  return (
    <div className={styles.page}>
      <div className={styles.tabs}>
        {attached.map((format) => (
          <button
            key={format}
            type="button"
            className={format === active ? styles.tabOn : styles.tab}
            onClick={() => dispatch(setActive(format))}
          >
            {labelFor(format)}
            <span className={styles.tabCount}>{(shots[format] ?? []).length}</span>
          </button>
        ))}

        <DropZone onFiles={accept} compact />

        <button
          type="button"
          className={styles.drop_}
          onClick={() => {
            releaseModel(active);
            dispatch(dropModel(active));
          }}
        >
          Remove {labelFor(active)}
        </button>
      </div>

      <CaptureStage format={active} uri={models[active].uri} probe={probe} onCapture={capture} />

      {activeShots.length > 0 && (
        <CameraRoll
          shots={activeShots}
          thumbnailIndex={thumbnail?.from === active ? thumbnail.index : -1}
          onRemove={(index) => dispatch(removeShot({ format: active, index }))}
          onRetake={retake}
          onMove={(index, by) => dispatch(moveShot({ format: active, index, by }))}
          onThumbnail={useAsThumbnail}
        />
      )}

      {errors.angles && <div className="field-error">{errors.angles}</div>}

      {Object.entries(fieldErrors).map(([field, messages]) => (
        <div key={field} className="field-error">
          {[].concat(messages).join(' ')}
        </div>
      ))}

      <div className={styles.details}>
        <label className={styles.field}>
          <span>Name</span>
          <input
            className="form-control"
            value={details.name}
            onInput={(event) => dispatch(setDetails({ name: event.target.value }))}
          />
          {errors.name && <div className="field-error">{errors.name}</div>}
        </label>

        <label className={styles.field}>
          <span>Description</span>
          <textarea
            className="form-control"
            rows="3"
            value={details.description}
            onInput={(event) => dispatch(setDetails({ description: event.target.value }))}
          />
        </label>

        <div className={styles.row}>
          <label className={styles.fieldNarrow}>
            <span>Price</span>
            <div className="input-group">
              <span className="input-group-text">$</span>
              <input
                className="form-control"
                value={details.price}
                onInput={(event) => dispatch(setDetails({ price: event.target.value }))}
              />
            </div>
            {errors.price && <div className="field-error">{errors.price}</div>}
          </label>

          <label className={styles.fieldNarrow}>
            <span>Buyers see</span>
            <select
              className="form-select"
              value={previewMode}
              onChange={(event) => dispatch(setPreviewMode(event.target.value))}
            >
              <option value={ATTESTED}>Images we render</option>
              <option value={INTERACTIVE}>The model itself</option>
            </select>
          </label>

          <div className={styles.thumbnailSlot}>
            <span>Thumbnail</span>
            {thumbnail ? (
              <img src={thumbnail.uri} alt="Thumbnail" />
            ) : (
              <DropZone onFiles={accept} compact />
            )}
            {errors.thumbnail && <div className="field-error">{errors.thumbnail}</div>}
          </div>
        </div>

        <SubmitButton
          className="btn btn-primary w-100"
          pending={status === 'loading'}
          onClick={submit}
        >
          Publish product
        </SubmitButton>
      </div>
    </div>
  );
};

export default ProductUploadPage;
