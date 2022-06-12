import { useState } from 'react';
import { Button } from 'react-bootstrap';
import ModelDisplayer from '../../common/model-displayer/ModelDisplayer';
import { ErrorBoundary } from 'react-error-boundary';
import ModelLoaderErrorFallback from '../product-view/ModelLoaderErrorFallback';
import { Suspense } from 'react';
const ProductUploadPage = () => {
  const [productName, setProductName] = useState('');
  const [productDescription, setProductDescription] = useState('');
  const [productPrice, setProductPrice] = useState('');
  const [productFile, setProductFile] = useState('');

  const onUploadClicked = () => {
    console.log('product upload');
  };

  let productView;

  const handleModelAttachment = async(e) => {
    let file = e.target.files[0];
    let objectUrl = URL.createObjectURL(file);
    let blob = await fetch(objectUrl).then(r => r.blob());
    console.log(blob);
  };

  if (!productFile) {
    productView = (
      <div className="bg-primary text-light">File preview will appear here</div>
    );
  } else {
    console.log(productFile);
    productView = (
      <ErrorBoundary FallbackComponent={ModelLoaderErrorFallback}>
        <ModelDisplayer fileUrl={productFile} isLocalFile="true"></ModelDisplayer>
      </ErrorBoundary>
    );
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
              value={productFile}
              onInput={(e) => handleModelAttachment(e)}
            />
          </div>
        </div>

        <div className="row mt-1">
          <div className="col">{productView}</div>
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
