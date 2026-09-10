import styles from './SingleProductView.module.css';
import { useParams } from 'react-router-dom';
import { useSelector, useDispatch } from 'react-redux';
import { useEffect } from 'react';
import { fetchProductById } from '../../../service/features/productSlice';
import { ErrorBoundary } from 'react-error-boundary';
import ModelLoaderErrorFallback from './ModelLoaderErrorFallback';
import LoadingSpinner from '../../common/spinner/LoadingSpinner';
import LoadError from '../../common/load-error/LoadError';
import { formatPrice } from '../../../service/util/formatPrice';
import AddToCartButton from './AddToCardButton/AddToCartButton';
import DownloadButton from '../../common/download-button/DownloadButton';
import ObjModelDisplayer from '../../common/model-displayer/ObjModelDisplayer';
import { useState } from 'react';
import GltfModelDisplayer from '../../common/model-displayer/GltfModelDisplayer';
import AttestedStills from '../../common/attested-stills/AttestedStills';

const SingleProductView = () => {
  const params = useParams();
  const productId = params.productId;

  const dispatch = useDispatch();
  const product = useSelector((state) => state.product.product);
  const productStatus = useSelector((state) => state.product.status);
  const error = useSelector((state) => state.product.error);

  const [chosenFileType, setChosenFileType] = useState(null);

  let content = '';

  useEffect(() => {
    dispatch(fetchProductById(productId));
  }, [dispatch, productId]);

  const formats = product?.formats ?? [];
  const selectedFileType = formats.includes(chosenFileType)
    ? chosenFileType
    : formats[0] ?? 'obj';

  if (productStatus === 'loading') {
    content = (
      <div className="d-flex justify-content-center align-items-center">
        <LoadingSpinner />
      </div>
    );
  }

  if (productStatus === 'failed') {
    content = <LoadError message={error} fallback="Could not load this product." />;
  }

  let objDownloadButton = <></>;
  let gltfDownloadButton = <></>;

  if (productStatus === 'succeeded') {
    let objComponent = <></>;

    if (product.preview_urls?.obj) {
      objComponent = (
        <ErrorBoundary FallbackComponent={ModelLoaderErrorFallback}>
          <ObjModelDisplayer fileUrl={product.preview_urls.obj}></ObjModelDisplayer>
        </ErrorBoundary>
      );
    }

    let gltfComponent = <></>;

    if (product.preview_urls?.gltf) {
      gltfComponent = (
        <ErrorBoundary FallbackComponent={ModelLoaderErrorFallback}>
          <GltfModelDisplayer fileUrl={product.preview_urls.gltf}></GltfModelDisplayer>
        </ErrorBoundary>
      );
    }

    const handleFiletypeSelectionChange = (event) => {
      setChosenFileType(event.target.value);
    };

    let componentToDisplay;
    if (product.preview_mode === 'attested_stills') {
      componentToDisplay = <AttestedStills product={product} />;
    } else if (selectedFileType === 'obj') {
      componentToDisplay = <>{objComponent}</>;
    } else {
      componentToDisplay = <>{gltfComponent}</>;
    }

    if (product.download_urls?.obj) {
      objDownloadButton = (
        <>
          <DownloadButton link={product.download_urls.obj} text="Download .obj" />
        </>
      );
    }

    if (product.download_urls?.gltf) {
      gltfDownloadButton = (
        <>
          <DownloadButton link={product.download_urls.gltf} text="Download .gltf" />
        </>
      );
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
                  <span>${formatPrice(product.price_cents)}</span>
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
                    value={selectedFileType}
                    onChange={(e) => handleFiletypeSelectionChange(e)}
                  >
                    <option disabled={!formats.includes('obj')} value="obj">
                      .obj
                    </option>
                    <option disabled={!formats.includes('gltf')} value="gltf">
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
