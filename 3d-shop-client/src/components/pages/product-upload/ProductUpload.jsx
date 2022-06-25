import { useState } from 'react';
import { Button } from 'react-bootstrap';
import { ErrorBoundary } from 'react-error-boundary';
import { useDispatch, useSelector } from 'react-redux';
import {
  clearUploadState,
  uploadProduct,
} from '../../../service/features/productUploadSlice';
import ModelDisplayer from '../../common/model-displayer/ModelDisplayer';
import { fileToDataUri } from '../../../service/util/fileToDataUri';
import ModelLoaderErrorFallback from '../product-view/ModelLoaderErrorFallback';
import { useNavigate } from 'react-router-dom';
import { toast } from 'react-toastify';

const ProductUploadPage = () => {
  const [productName, setProductName] = useState('');
  const [productDescription, setProductDescription] = useState('');
  const [productPrice, setProductPrice] = useState('');
  const [productFile, setProductFile] = useState('');
  const [modelUri, setModelUri] = useState('');

  const [productThumbnail, setProductThumbnail] = useState('');
  const [thumbnailUri, setThumbnailUri] = useState('');

  const dispatch = useDispatch();
  const navigate = useNavigate();

  const uploadedProduct = useSelector((state) => state.productUpload.uploadedProduct);
  const status = useSelector((state) => state.productUpload.status);
  const error = useSelector((state) => state.productUpload.error);

  const onUploadClicked = () => {
    const formData = new FormData();

    formData.append('model', productFile);
    formData.append('thumbnail', productThumbnail);
    formData.append('name', productName);
    formData.append('price', productPrice);
    formData.append('description', productDescription);

    let body = formData;

    dispatch(uploadProduct(body));
  };

  const handleModelAttachment = (e) => {
    var file = e.target.files[0];
    setProductFile(file);

    fileToDataUri(file).then((uri) => {
      setModelUri(uri);
    });
  };

  const handleThumbnailAttachment = (e) => {
    var file = e.target.files[0];
    setProductThumbnail(file);

    fileToDataUri(file).then((uri) => {
      setThumbnailUri(uri);
    });
  };

  let productPreview;

  if (!modelUri) {
    productPreview = <></>;
  } else {
    productPreview = (
      <ErrorBoundary FallbackComponent={ModelLoaderErrorFallback}>
        <ModelDisplayer fileUrl={modelUri} isLocalFile={true} />
      </ErrorBoundary>
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

        <div className="row mt-1 mb-3">
          <div className="col">
            <label htmlFor="formFile" className="form-label">
              Your model (3d file)
            </label>
            <input
              className="form-control"
              type="file"
              onChange={(e) => handleModelAttachment(e)}
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
          <div className="col">{productPreview}</div>
          <div className="col">
            <img className="product-thumbnail" src={thumbnailUri}></img>
          </div>
        </div>

        <div className="row mt-3">
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
