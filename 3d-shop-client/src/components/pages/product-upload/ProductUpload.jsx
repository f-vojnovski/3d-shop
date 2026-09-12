import { useCallback, useEffect, useRef } from 'react';
import { useDispatch, useSelector } from 'react-redux';
import { useNavigate } from 'react-router-dom';
import { postForBinary } from '../../../service/api/axiosClient';
import { clearUploadState, uploadProduct } from '../../../service/features/productUploadSlice';
import {
  addSellerImage,
  addShot,
  attachModel,
  dropModel,
  moveShot,
  removeSellerImage,
  removeShot,
  resetDraft,
  retakeShot,
  selectActiveShots,
  selectAttachedFormats,
  setActive,
  setDetails,
  setErrors,
  setPreviewMode,
  addThumbnail,
  removeThumbnail,
  alsoAsThumbnail,
  alsoAsPreviewImage,
  convertingStarted,
  convertedPreview,
  conversionFailed,
} from '../../../service/features/uploadDraftSlice';
import { clearModelCache } from '../../common/model-displayer/modelCache';
import { fileToDataUri } from '../../../service/util/fileToDataUri';
import { firstErrors, price as validatePrice, required } from '../../../service/util/validate';
import { notify } from '../../../service/features/toastSlice';
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
  needsConverting,
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
  const {
    active,
    details,
    errors,
    models,
    previewMode,
    sellerImages,
    shots,
    converting,
    thumbnails,
  } = useSelector((state) => state.uploadDraft);
  const token = useSelector((state) => state.auth.token);
  const attached = useSelector(selectAttachedFormats);
  const activeShots = useSelector(selectActiveShots);

  const uploaded = useSelector((state) => state.productUpload.uploadedProduct);
  const status = useSelector((state) => state.productUpload.status);
  const error = useSelector((state) => state.productUpload.error);
  const fieldErrors = useSelector((state) => state.productUpload.fieldErrors);

  // A handle into the live canvas, not state.
  const probe = useRef(null);
  const framingUri = active
    ? (needsConverting(active) ? models[active]?.previewUri : models[active]?.uri)
    : null;

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
      dispatch(notify('success', 'Your product is live.'));
      navigate(`/product/${uploaded.id}`);
    }
  }, [status, uploaded, models, dispatch, navigate]);

  useEffect(() => {
    if (status === 'failed') {
      dispatch(notify('error', error || 'Upload failed. Check the files and try again.'));
      dispatch(clearUploadState());
    }
  }, [status, error, dispatch]);

  // The original file is still what gets uploaded; this copy only aims a camera.
  const convertForFraming = useCallback(async (format, file) => {
    dispatch(convertingStarted(format));

    const form = new FormData();
    form.append('model', file);

    try {
      const bytes = await postForBinary('api/uploads/convert', form, token);
      const previewUri = URL.createObjectURL(new Blob([bytes], { type: 'model/gltf-binary' }));

      dispatch(convertedPreview({ format, previewUri }));
    } catch {
      dispatch(conversionFailed(format));
      dispatch(notify('error', `${labelFor(format)} could not be prepared for framing.`));
    }
  }, [dispatch, token]);

  const accept = useCallback(async (files, into = 'thumbnails') => {
    for (const file of files) {
      const format = formatOf(file);

      if (format) {
        if (file.size > MAX_MODEL_BYTES) {
          dispatch(
            notify('error', `${file.name} is ${megabytes(file.size)}; the limit is ${megabytes(MAX_MODEL_BYTES)}.`)
          );
          continue;
        }

        dispatch(attachModel({ format, file, uri: URL.createObjectURL(file) }));

        if (needsConverting(format)) {
          convertForFraming(format, file);
        }

        continue;
      }

      if (isImage(file)) {
        if (file.size > MAX_IMAGE_BYTES) {
          dispatch(
            notify('error', `${file.name} is ${megabytes(file.size)}; the limit is ${megabytes(MAX_IMAGE_BYTES)}.`)
          );
          continue;
        }

        const uri = await fileToDataUri(file);

        dispatch(into === 'images' ? addSellerImage({ file, uri }) : addThumbnail({ file, uri }));

        continue;
      }

      dispatch(notify('error', `${file.name} is not supported. Use ${SUPPORTED_SUMMARY}.`));
    }
  }, [dispatch, convertForFraming]);

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

  const addShotAsThumbnail = async (index) => {
    const shot = activeShots[index];

    dispatch(
      addThumbnail({
        file: await dataUriToFile(shot.snapshot, `${active}-thumbnail.jpg`),
        uri: shot.snapshot,
      })
    );
  };

  const submit = () => {
    // A render can stand in for a thumbnail the seller never framed.
    const rendersWillSupplyOne =
      previewMode === ATTESTED && attached.some((format) => (shots[format] ?? []).length > 0);

    const missing = previewMode === ATTESTED
      ? attached.filter((format) => (shots[format] ?? []).length === 0)
      : [];

    const found = firstErrors({
      name: required(details.name, 'A name'),
      price: validatePrice(details.price),
      thumbnail: thumbnails.length > 0 || rendersWillSupplyOne
        ? null
        : 'Add a thumbnail: capture one, or drop an image.',
      angles: missing.length === 0
        ? null
        : `Frame a view of ${missing.map(labelFor).join(' and ')}.`,
    });

    dispatch(setErrors(found));

    if (Object.keys(found).length > 0) {
      return;
    }

    const form = new FormData();

    FORMATS.filter((format) => models[format.key]).forEach((format) => {
      form.append(format.field, models[format.key].file);
    });

    thumbnails.forEach((image, index) => {
      form.append(`thumbnails[${index}]`, image.file);
    });

    sellerImages.forEach((image, index) => {
      form.append(`images[${index}]`, image.file);
    });
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

      {framingUri ? (
        <>
          <CaptureStage format={active} uri={framingUri} probe={probe} onCapture={capture} />

          {models[active].previewUri && (
            <p className={styles.converted}>
              No browser opens a {labelFor(active)}, so this is the .glb our server will render.
              Frame it and the camera lands where you aimed it.
            </p>
          )}
        </>
      ) : (
        <div className={styles.standardOnly}>
          {converting[active]
            ? `Preparing a copy of ${labelFor(active)} you can frame...`
            : `${labelFor(active)} could not be prepared for framing. Remove it and try again.`}
        </div>
      )}

      {activeShots.length > 0 && (
        <CameraRoll
          shots={activeShots}
          onRemove={(index) => dispatch(removeShot({ format: active, index }))}
          onRetake={retake}
          onMove={(index, by) => dispatch(moveShot({ format: active, index, by }))}
          onThumbnail={addShotAsThumbnail}
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
            <span>Thumbnails</span>
            <div className={styles.sellerImages}>
              {thumbnails.map((image, index) => (
                <figure key={image.uri} className={styles.picture}>
                  <img src={image.uri} alt={`Thumbnail ${index + 1}`} />
                  <figcaption>
                    <button
                      type="button"
                      title="Also show as a preview image"
                      onClick={() => dispatch(alsoAsPreviewImage(index))}
                    >
                      &darr;
                    </button>
                    <button
                      type="button"
                      title="Remove"
                      onClick={() => dispatch(removeThumbnail(index))}
                    >
                      &times;
                    </button>
                  </figcaption>
                </figure>
              ))}
              <DropZone onFiles={accept} compact />
            </div>
            {errors.thumbnail && <div className="field-error">{errors.thumbnail}</div>}
          </div>

          <div className={styles.thumbnailSlot}>
            <span>Uploaded preview images (optional)</span>
            <div className={styles.sellerImages}>
              {sellerImages.map((image, index) => (
                <figure key={image.uri} className={styles.picture}>
                  <img src={image.uri} alt={`Preview image ${index + 1}`} />
                  <figcaption>
                    <button
                      type="button"
                      title="Also use as a thumbnail"
                      onClick={() => dispatch(alsoAsThumbnail(index))}
                    >
                      &uarr;
                    </button>
                    <button
                      type="button"
                      title="Remove"
                      onClick={() => dispatch(removeSellerImage(index))}
                    >
                      &times;
                    </button>
                  </figcaption>
                </figure>
              ))}
              <DropZone onFiles={(files) => accept(files, 'images')} compact />
            </div>
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
