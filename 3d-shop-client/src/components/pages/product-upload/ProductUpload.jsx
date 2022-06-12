import { useState } from 'react';
import { Button } from 'react-bootstrap';
import { useDispatch, useSelector } from 'react-redux';
import { uploadProduct } from '../../../service/features/productUploadSlice';
const ProductUploadPage = () => {
  const [productName, setProductName] = useState('');
  const [productDescription, setProductDescription] = useState('');
  const [productPrice, setProductPrice] = useState('');
  const [productFile, setProductFile] = useState('');

  const dispatch = useDispatch();

  const token = useSelector((state) => state.auth.token);

  const onUploadClicked = () => {
    const formData = new FormData();

    console.log(productFile);

    formData.append('model', productFile);
    formData.append('name', productName);
    formData.append('price', productPrice);
    formData.append('description', productDescription);

    let obj = {
      body: formData,
      token: token,
    };

    dispatch(uploadProduct(obj));
  };

  const handleModelAttachment = (e) => {
    var file = e.target.files[0];
    setProductFile(file);
  };

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
