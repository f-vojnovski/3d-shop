import styles from './SingleProductView.module.css';
import { useParams } from 'react-router-dom';
import { useSelector, useDispatch } from 'react-redux';
import { useEffect } from 'react';
import { fetchProductById } from '../../../service/features/productSlice';
import { ErrorBoundary } from 'react-error-boundary';
import ModelLoaderErrorFallback from './ModelLoaderErrorFallback';
import { resetProduct } from '../../../service/features/productSlice';
import LoadingSpinner from '../../common/spinner/LoadingSpinner';
import AddToCartButton from './AddToCardButton/AddToCartButton';
import DownloadButton from '../../common/download-button/DownloadButton';
import { API_URL } from '../../../consts';
import ObjModelDisplayer from '../../common/model-displayer/ObjModelDisplayer';
import { useState } from 'react';
import GltfModelDisplayer from '../../common/model-displayer/GltfModelDisplayer';

const SingleProductView = () => {
  const params = useParams();
  const productId = params.productId;

  const dispatch = useDispatch();
  const product = useSelector((state) => state.product.product);
  const productStatus = useSelector((state) => state.product.status);
  const error = useSelector((state) => state.product.error);

  const [selectedFileType, setSelectedFileType] = useState('obj');

  let content = '';

  useEffect(() => {
    if (product && product.id != productId) {
      dispatch(resetProduct());
    }
  }, []);

  useEffect(() => {
    if (productStatus === 'idle') {
      dispatch(fetchProductById(productId));
    }
  }, [productStatus, dispatch, productId]);

  useEffect(() => {
    if (productStatus === 'succeeded') {
      if (!product.obj_file_path) {
        setSelectedFileType('gltf');
      }
    }
  }, [productStatus, dispatch, productId]);

  if (productStatus === 'loading') {
    content = (
      <div className="d-flex justify-content-center align-items-center">
        <LoadingSpinner />
      </div>
    );
  }

  if (productStatus === 'error') {
    content = (
      <div className="d-flex justify-content-center align-items-center">
        <div className="row mt-3">
          <div className="col">
            <div className="alert alert-danger" role="alert">
              Error while loading product!
            </div>
          </div>
        </div>
      </div>
    );
  }

  let objDownloadButton = <></>;
  let gltfDownloadButton = <></>;

  if (productStatus === 'succeeded') {
    let objComponent = <></>;

    if (product.obj_file_path) {
      objComponent = (
        <ErrorBoundary FallbackComponent={ModelLoaderErrorFallback}>
          <ObjModelDisplayer fileUrl={product.obj_file_path}></ObjModelDisplayer>
        </ErrorBoundary>
      );
    }

    let gltfComponent = <></>;

    if (product.gltf_file_path) {
      gltfComponent = (
        <ErrorBoundary FallbackComponent={ModelLoaderErrorFallback}>
          <GltfModelDisplayer fileUrl={product.gltf_file_path}></GltfModelDisplayer>
        </ErrorBoundary>
      );
    }

    const handleFiletypeSelectionChange = (event) => {
      setSelectedFileType(event.target.value);
    };

    let componentToDisplay;
    if (selectedFileType === 'obj') {
      componentToDisplay = <>{objComponent}</>;
    } else {
      componentToDisplay = <>{gltfComponent}</>;
    }

    if (product.product_status === 'owner' || product.product_status === 'purchased') {
      if (product.obj_file_path) {
        objDownloadButton = (
          <>
            <DownloadButton
              link={`${API_URL}${product.obj_file_path}`}
              text="Download .obj"
            />
          </>
        );
      }

      if (product.gltf_file_path) {
        gltfDownloadButton = (
          <>
            <DownloadButton
              link={`${API_URL}${product.gltf_file_path}`}
              text="Download .gltf"
            />
          </>
        );
      }
    }

    content = (
      <div className={styles.view_container}>
        <div className={styles.model_details_container}>
          <div className={styles.model_container}>
            <div className={styles.model_container_dummy}></div>
            <div className={styles.model}>{componentToDisplay}</div>
          </div>
          <div className={styles.model_info_container}>
            <div className="container-fluid w-100 h-100">
              <div className="row mb-2 large-font">
                <div className="col">
                  <span className="bolded-label">{product.name}</span>
                  <span> - </span>
                  <span>${product.price}</span>
                </div>
              </div>
              <div className="row mb-2">
                <div className="col">
                  <span>{product.description}</span>
                </div>
              </div>
              <div className="d-flex row mt-auto mb-0">
                <div className="col d-flex align-self-end">
                  <AddToCartButton product={product} />
                </div>
              </div>
              <div className="row mt-2">
                <div className="col">
                  <select
                    className="form-select"
                    value={selectedFileType.value}
                    onChange={(e) => handleFiletypeSelectionChange(e)}
                  >
                    <option disabled={product.obj_file_path == null} value="obj">
                      .obj
                    </option>
                    <option disabled={product.gltf_file_path == null} value="gltf">
                      .gltf
                    </option>
                  </select>
                </div>
              </div>
              {objDownloadButton && (
                <div className="d-flex row mt-auto mb-0">
                  <div className="col d-flex align-self-end">{objDownloadButton}</div>
                </div>
              )}

              {gltfDownloadButton && (
                <div className="d-flex row mt-auto mb-0">
                  <div className="col d-flex align-self-end">{gltfDownloadButton}</div>
                </div>
              )}
            </div>
          </div>
        </div>
      </div>
    );
  }

  return <div>{content}</div>;
};

export default SingleProductView;
