import styles from './SingleProductView.module.css';
import { useParams } from 'react-router-dom';
import { useSelector, useDispatch } from 'react-redux';
import { useEffect, useState } from 'react';
import { fetchProductById } from '../../../service/features/productSlice';
import { ErrorBoundary } from 'react-error-boundary';
import ModelLoaderErrorFallback from './ModelLoaderErrorFallback';
import LoadingSpinner from '../../common/spinner/LoadingSpinner';
import LoadError from '../../common/load-error/LoadError';
import { formatPrice } from '../../../service/util/formatPrice';
import AddToCartButton from './AddToCardButton/AddToCartButton';
import DownloadButton from '../../common/download-button/DownloadButton';
import ObjModelDisplayer from '../../common/model-displayer/ObjModelDisplayer';
import GltfModelDisplayer from '../../common/model-displayer/GltfModelDisplayer';
import AttestedStills from '../../common/attested-stills/AttestedStills';
import { BsPersonCircle } from 'react-icons/bs';

const SingleProductView = () => {
  const { productId } = useParams();

  const dispatch = useDispatch();
  const product = useSelector((state) => state.product.product);
  const productStatus = useSelector((state) => state.product.status);
  const error = useSelector((state) => state.product.error);

  const [chosenFileType, setChosenFileType] = useState(null);

  useEffect(() => {
    dispatch(fetchProductById(productId));
  }, [dispatch, productId]);

  const renderInProgress =
    product?.id === Number(productId) &&
    ['queued', 'rendering'].includes(product?.preview_status);

  useEffect(() => {
    if (!renderInProgress) {
      return undefined;
    }

    const timer = setInterval(() => dispatch(fetchProductById(productId)), 3000);

    return () => clearInterval(timer);
  }, [renderInProgress, dispatch, productId]);

  const formats = product?.formats ?? [];
  const selectedFileType = formats.includes(chosenFileType) ? chosenFileType : formats[0] ?? 'obj';

  if (productStatus === 'loading') {
    return (
      <div className="d-flex justify-content-center align-items-center">
        <LoadingSpinner />
      </div>
    );
  }

  if (productStatus !== 'succeeded') {
    return <LoadError message={error} fallback="Could not load this product." />;
  }

  const viewer = (Displayer, url) => (
    <ErrorBoundary FallbackComponent={ModelLoaderErrorFallback}>
      <Displayer fileUrl={url} />
    </ErrorBoundary>
  );

  let media;
  if (product.preview_mode === 'attested_stills') {
    media = <AttestedStills product={product} />;
  } else if (selectedFileType === 'gltf' && product.preview_urls?.gltf) {
    media = viewer(GltfModelDisplayer, product.preview_urls.gltf);
  } else if (product.preview_urls?.obj) {
    media = viewer(ObjModelDisplayer, product.preview_urls.obj);
  }

  const downloads = formats
    .filter((format) => product.download_urls?.[format])
    .map((format) => (
      <DownloadButton
        key={format}
        link={product.download_urls[format]}
        text={`Download .${format}`}
      />
    ));

  return (
    <div className={styles.page}>
      <div className={styles.layout}>
        <div className={styles.media}>{media}</div>

        <div className={styles.rail}>
          <div className={styles.seller}>
            <BsPersonCircle /> Seller #{product.user_id}
          </div>

          <h1 className={styles.title}>{product.name}</h1>
          <div className={styles.price}>${formatPrice(product.price_cents)}</div>

          <AddToCartButton product={product} />

          {product.description && <p className={styles.description}>{product.description}</p>}

          {formats.length > 0 && (
            <div className={styles.section}>
              <p className={styles.sectionLabel}>Included formats</p>
              <div className={styles.formats}>
                {formats.map((format) => (
                  <span key={format} className={styles.format}>
                    .{format}
                  </span>
                ))}
              </div>
            </div>
          )}

          {formats.length > 1 && product.preview_mode !== 'attested_stills' && (
            <div className={styles.section}>
              <p className={styles.sectionLabel}>Preview format</p>
              <select
                className="form-select form-select-sm"
                value={selectedFileType}
                onChange={(event) => setChosenFileType(event.target.value)}
              >
                {formats.map((format) => (
                  <option key={format} value={format}>
                    .{format}
                  </option>
                ))}
              </select>
            </div>
          )}

          {downloads.length > 0 && (
            <div className={styles.section}>
              <p className={styles.sectionLabel}>Your files</p>
              <div className={styles.downloads}>{downloads}</div>
            </div>
          )}
        </div>
      </div>
    </div>
  );
};

export default SingleProductView;
