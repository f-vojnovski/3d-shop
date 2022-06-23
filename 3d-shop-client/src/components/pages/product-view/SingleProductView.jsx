import ModelDisplayer from '../../common/model-displayer/ModelDisplayer';
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

const SingleProductView = () => {
  const params = useParams();
  const productId = params.productId;

  const dispatch = useDispatch();
  const product = useSelector((state) => state.product.product);
  const productStatus = useSelector((state) => state.product.status);

  const error = useSelector((state) => state.product.error);

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

  if (productStatus === 'succeeded') {
    content = (
      <div className={styles.view_container}>
        <div className={styles.model_details_container}>
          <div className={styles.model_container}>
            <div className={styles.model_container_dummy}></div>
            <div className={styles.model}>
              <ErrorBoundary FallbackComponent={ModelLoaderErrorFallback}>
                <ModelDisplayer fileUrl={product.obj_file_path}></ModelDisplayer>
              </ErrorBoundary>
            </div>
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
            </div>
          </div>
        </div>
      </div>
    );
  }

  return <div>{content}</div>;
};

export default SingleProductView;
