import { useState } from 'react';
import { Button } from 'react-bootstrap';
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

  const dispatch = useDispatch();
  const navigate = useNavigate();

  const uploadedProduct = useSelector((state) => state.productUpload.uploadedProduct);
  const status = useSelector((state) => state.productUpload.status);
  const error = useSelector((state) => state.productUpload.error);

  const onUploadClicked = () => {
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

    let body = formData;

    dispatch(uploadProduct(body));
  };

  const handleGltfModelAttachment = (e) => {
    var file = e.target.files[0];
    setGltfProductFile(file);

    fileToDataUri(file).then((uri) => {
      setGltfModelUri(uri);
    });
  };

  const handleObjModelAttachment = (e) => {
    var file = e.target.files[0];
    setObjProductFile(file);

    fileToDataUri(file).then((uri) => {
      setObjModelUri(uri);
    });
  };

  const handleThumbnailAttachment = (e) => {
    var file = e.target.files[0];
    setProductThumbnail(file);

    fileToDataUri(file).then((uri) => {
      setThumbnailUri(uri);
    });
  };

  let gltfProductPreview;

  if (!gltfModelUri) {
    gltfProductPreview = <></>;
  } else {
    gltfProductPreview = (
      <div className="square">
        <ErrorBoundary FallbackComponent={ModelLoaderErrorFallback}>
          <GltfModelDisplayer fileUrl={gltfModelUri} isLocalFile={true} />
        </ErrorBoundary>
      </div>
    );
  }

  let objProductPreview;

  if (!objModelUri) {
    objProductPreview = <></>;
  } else {
    objProductPreview = (
      <div className="square">
        <ErrorBoundary FallbackComponent={ModelLoaderErrorFallback}>
          <ObjModelDisplayer fileUrl={objModelUri} isLocalFile={true} />
        </ErrorBoundary>
      </div>
    );
  }

  if (status === 'succeeded') {
    dispatch(clearUploadState());
    toast.success('Your new product has been uploaded!');
    navigate(`/product/${uploadedProduct.id}`);
  }

  return (
    <div className="container-fluid my-auto form_max_width">
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
        </div>

        <div className="row mt-4 mb-2">
          <div className="col">
            <h5>Upload your model in at least one of the specified formats!</h5>
          </div>
        </div>

        <div className="row mt-1 mb-3">
          <div className="col">
            <label htmlFor="formFile" className="form-label">
              Your model (.gltf file)
            </label>
            <input
              className="form-control"
              type="file"
              onChange={(e) => handleGltfModelAttachment(e)}
            />
          </div>
        </div>

        <div className="row mt-1 mb-3">
          <div className="col">
            <label htmlFor="formFile" className="form-label">
              Your model (.obj file)
            </label>
            <input
              className="form-control"
              type="file"
              onChange={(e) => handleObjModelAttachment(e)}
            />
          </div>
        </div>

        <div className="row mt-1 mb-3">
          <div className="col">
            <label htmlFor="formFile" className="form-label">
              Thumbnail (image file)
            </label>
            <input
              className="form-control"
              type="file"
              onChange={(e) => handleThumbnailAttachment(e)}
            />
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
            <img className="product-thumbnail" src={thumbnailUri}></img>
          </div>
        </div>

        <div className="row mt-1">
          <div className="col">
            Please make sure that the product previews <strong>work</strong> before
            uploading your model.
          </div>
        </div>

        <div className="row mt-3 mb-5">
          <div className="col">
            <Button
              className="w-100"
              onClick={() => {
                onUploadClicked();
              }}
            >
              Upload product!
            </Button>
          </div>
        </div>
      </div>
    </div>
  );
};

export default ProductUploadPage;
