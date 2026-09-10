import { useEffect, useRef, useState } from 'react';
import { ErrorBoundary } from 'react-error-boundary';
import { useDispatch, useSelector } from 'react-redux';
import {
  clearUploadState,
  uploadProduct,
} from '../../../service/features/productUploadSlice';
import { fileToDataUri } from '../../../service/util/fileToDataUri';
import ModelLoaderErrorFallback from '../product-view/ModelLoaderErrorFallback';
import { useNavigate } from 'react-router-dom';
import { toast } from 'react-toastify';
import ObjModelDisplayer from '../../common/model-displayer/ObjModelDisplayer';
import GltfModelDisplayer from '../../common/model-displayer/GltfModelDisplayer';
import PreviewAnglePicker from './PreviewAnglePicker';
import SubmitButton from '../../common/submit-button/SubmitButton';
import { firstErrors, price as validatePrice, required } from '../../../service/util/validate';

const ProductUploadPage = () => {
  const [productName, setProductName] = useState('');
  const [productDescription, setProductDescription] = useState('');
  const [productPrice, setProductPrice] = useState('');

  const [gltfProductFile, setGltfProductFile] = useState('');
  const [gltfModelUri, setGltfModelUri] = useState('');

  const [objProductFile, setObjProductFile] = useState('');
  const [objModelUri, setObjModelUri] = useState('');

  const [productThumbnail, setProductThumbnail] = useState('');
  const [thumbnailUri, setThumbnailUri] = useState('');

  const [previewMode, setPreviewMode] = useState('interactive');
  const [angles, setAngles] = useState([]);

  const [errors, setErrors] = useState({});

  // Filled in by CameraProbe from inside whichever preview canvas is showing.
  const probeRef = useRef(null);
  const gltfInputRef = useRef(null);
  const objInputRef = useRef(null);
  const thumbnailInputRef = useRef(null);

  const dispatch = useDispatch();
  const navigate = useNavigate();

  const uploadedProduct = useSelector((state) => state.productUpload.uploadedProduct);
  const status = useSelector((state) => state.productUpload.status);
  const error = useSelector((state) => state.productUpload.error);

  useEffect(() => {
    if (status === 'succeeded' && uploadedProduct) {
      dispatch(clearUploadState());
      toast.success('Your new product has been uploaded!');
      navigate(`/product/${uploadedProduct.id}`);
    }
  }, [status, uploadedProduct, dispatch, navigate]);

  useEffect(() => {
    if (status === 'failed') {
      toast.error(error || 'Upload failed. Please check the files and try again.');
    }
  }, [status, error]);

  const validate = () => {
    const found = firstErrors({
      name: required(productName, 'A product name'),
      price: validatePrice(productPrice),
      model:
        gltfProductFile || objProductFile ? null : 'Attach at least one model file (.obj or .gltf).',
      thumbnail: productThumbnail ? null : 'A thumbnail image is required.',
      angles:
        previewMode === 'attested_stills' && angles.length === 0
          ? 'Capture at least one camera angle for server-rendered previews.'
          : null,
    });

    setErrors(found);

    return Object.keys(found).length === 0;
  };

  const onUploadClicked = () => {
    if (!validate()) {
      return;
    }

    const formData = new FormData();

    if (gltfProductFile) {
      formData.append('gltfModel', gltfProductFile);
    }
    if (objProductFile) {
      formData.append('objModel', objProductFile);
    }
    formData.append('thumbnail', productThumbnail);
    formData.append('name', productName);
    formData.append('price', productPrice);
    formData.append('description', productDescription);
    formData.append('preview_mode', previewMode);

    if (previewMode === 'attested_stills') {
      formData.append('preview_angles', JSON.stringify(angles));
    }

    dispatch(uploadProduct(formData));
  };

  const attach =
    ({ extensions, label, setFile, setUri }) =>
    (event) => {
      const file = event.target.files[0];

      if (!file) {
        return;
      }

      const name = file.name.toLowerCase();

      // Rejected here so a wrong file never reaches the model viewer.
      if (!extensions.some((extension) => name.endsWith(extension))) {
        toast.error(`${label} must be ${extensions.join(' or ')}.`);
        event.target.value = '';
        return;
      }

      setFile(file);

      fileToDataUri(file)
        .then(setUri)
        .catch(() => {
          toast.error(`Could not read ${label}. Please pick the file again.`);
        });
    };

  const detach = ({ input, setFile, setUri }) => {
    setFile('');
    setUri('');

    if (input.current) {
      input.current.value = '';
    }
  };

  let gltfProductPreview;

  if (!gltfModelUri) {
    gltfProductPreview = <></>;
  } else {
    gltfProductPreview = (
      <div className="preview-square">
        <ErrorBoundary FallbackComponent={ModelLoaderErrorFallback}>
          <GltfModelDisplayer fileUrl={gltfModelUri} isLocalFile={true} probeRef={probeRef} />
        </ErrorBoundary>
      </div>
    );
  }

  let objProductPreview;

  if (!objModelUri) {
    objProductPreview = <></>;
  } else {
    objProductPreview = (
      <div className="preview-square">
        <ErrorBoundary FallbackComponent={ModelLoaderErrorFallback}>
          <ObjModelDisplayer fileUrl={objModelUri} isLocalFile={true} probeRef={probeRef} />
        </ErrorBoundary>
      </div>
    );
  }


  const captureAngle = () => {
    const camera = probeRef.current?.();

    if (!camera) {
      toast.error('The preview is not ready yet.');
      return;
    }

    setAngles((current) => [...current, camera]);
  };

  const removeAngle = (index) =>
    setAngles((current) => current.filter((_, i) => i !== index));

  return (
    <div className="form-shell">
      <div className="row mt-1">
        <div className="col">
          <h1>Upload your product!</h1>
        </div>
      </div>

      <div className="row mt-1">
        <div className="col">
          <label>Name of your product</label>
          <input
            type="default"
            className="form-control"
            value={productName}
            onInput={(e) => setProductName(e.target.value)}
          />
          {errors.name && <div className="field-error">{errors.name}</div>}
        </div>
      </div>

      <div className="row mt-1">
        <div className="col">
          <label>Describe your product</label>
          <textarea
            rows="5"
            type="default"
            className="form-control span6"
            value={productDescription}
            onInput={(e) => setProductDescription(e.target.value)}
          />
        </div>
      </div>

      <div className="row mt-1">
        <div className="col">
          <label>Pricing of your product</label>
          <div className="input-group w-25">
            <div className="input-group-prepend">
              <div className="input-group-text">$</div>
            </div>
            <input
              type="default"
              className="form-control"
              value={productPrice}
              onInput={(e) => setProductPrice(e.target.value)}
            />
          </div>
          {errors.price && <div className="field-error">{errors.price}</div>}
        </div>

        <div className="row mt-4 mb-2">
          <div className="col">
            <h5>Your model files</h5>
            <div className="form-text">
              At least one of .obj or .gltf is required. You can provide both.
            </div>
            {errors.model && <div className="field-error">{errors.model}</div>}
          </div>
        </div>

        <div className="row mt-1 mb-3">
          <div className="col">
            <label className="form-label">Your model (.gltf or .glb file)</label>
            <div className="d-flex gap-2">
              <input
                ref={gltfInputRef}
                className="form-control"
                type="file"
                accept=".gltf,.glb"
                onChange={attach({
                  extensions: ['.gltf', '.glb'],
                  label: 'The model',
                  setFile: setGltfProductFile,
                  setUri: setGltfModelUri,
                })}
              />
              {gltfProductFile && (
                <button
                  type="button"
                  className="btn btn-outline-secondary text-nowrap"
                  onClick={() =>
                    detach({
                      input: gltfInputRef,
                      setFile: setGltfProductFile,
                      setUri: setGltfModelUri,
                    })
                  }
                >
                  Remove
                </button>
              )}
            </div>
          </div>
        </div>

        <div className="row mt-1 mb-3">
          <div className="col">
            <label className="form-label">Your model (.obj file)</label>
            <div className="d-flex gap-2">
              <input
                ref={objInputRef}
                className="form-control"
                type="file"
                accept=".obj"
                onChange={attach({
                  extensions: ['.obj'],
                  label: 'The model',
                  setFile: setObjProductFile,
                  setUri: setObjModelUri,
                })}
              />
              {objProductFile && (
                <button
                  type="button"
                  className="btn btn-outline-secondary text-nowrap"
                  onClick={() =>
                    detach({
                      input: objInputRef,
                      setFile: setObjProductFile,
                      setUri: setObjModelUri,
                    })
                  }
                >
                  Remove
                </button>
              )}
            </div>
          </div>
        </div>

        <div className="row mt-1 mb-3">
          <div className="col">
            <label className="form-label">Thumbnail image (required)</label>
            <div className="d-flex gap-2">
              <input
                ref={thumbnailInputRef}
                className="form-control"
                type="file"
                accept="image/*"
                onChange={attach({
                  extensions: ['.png', '.jpg', '.jpeg', '.webp', '.gif'],
                  label: 'The thumbnail',
                  setFile: setProductThumbnail,
                  setUri: setThumbnailUri,
                })}
              />
              {productThumbnail && (
                <button
                  type="button"
                  className="btn btn-outline-secondary text-nowrap"
                  onClick={() =>
                    detach({
                      input: thumbnailInputRef,
                      setFile: setProductThumbnail,
                      setUri: setThumbnailUri,
                    })
                  }
                >
                  Remove
                </button>
              )}
            </div>
            {errors.thumbnail && <div className="field-error">{errors.thumbnail}</div>}
          </div>
        </div>

        <div className="row mt-1">
          <div className="col">
            <p>GLTF:</p>
          </div>
        </div>

        <div className="row mt-1">
          <div className="col d-flex justify-content-center">{gltfProductPreview}</div>
        </div>

        <div className="row mt-1">
          <div className="col">
            <p>Obj:</p>
          </div>
        </div>

        <div className="row mt-1">
          <div className="col d-flex justify-content-center">{objProductPreview}</div>
        </div>

        <div className="row mt-1">
          <div className="col d-flex justify-content-center">
            {thumbnailUri && (
              <img className="product-thumbnail" src={thumbnailUri} alt="Thumbnail preview" />
            )}
          </div>
        </div>

        <PreviewAnglePicker
          previewMode={previewMode}
          onPreviewModeChange={setPreviewMode}
          angles={angles}
          onCapture={captureAngle}
          onRemove={removeAngle}
          canCapture={Boolean(objModelUri || gltfModelUri)}
        />

        {errors.angles && (
          <div className="row mt-1">
            <div className="col">
              <div className="field-error">{errors.angles}</div>
            </div>
          </div>
        )}

        <div className="row mt-1">
          <div className="col">
            Please make sure that the product previews <strong>work</strong> before
            uploading your model.
          </div>
        </div>

        <div className="row mt-3 mb-5">
          <div className="col">
            <SubmitButton
              className="btn btn-primary w-100"
              pending={status === 'loading'}
              onClick={() => onUploadClicked()}
            >
              Upload product!
            </SubmitButton>
          </div>
        </div>
      </div>
    </div>
  );
};

export default ProductUploadPage;
