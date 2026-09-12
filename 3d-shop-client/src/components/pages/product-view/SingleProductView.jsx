import styles from './SingleProductView.module.css';
import { useParams } from 'react-router-dom';
import { useSelector, useDispatch } from 'react-redux';
import { useEffect, useState } from 'react';
import {
  fetchProductById,
  replaceFile,
  withdrawProduct,
} from '../../../service/features/productSlice';
import { createEcho } from '../../../service/realtime/echo';
import { ErrorBoundary } from 'react-error-boundary';
import ModelLoaderErrorFallback from './ModelLoaderErrorFallback';
import LoadingSpinner from '../../common/spinner/LoadingSpinner';
import LoadError from '../../common/load-error/LoadError';
import { formatPrice } from '../../../service/util/formatPrice';
import AddToCartButton from './AddToCardButton/AddToCartButton';
import DownloadButton from '../../common/download-button/DownloadButton';
import BundleContents from '../../common/bundle-contents/BundleContents';
import ObjModelDisplayer from '../../common/model-displayer/ObjModelDisplayer';
import GltfModelDisplayer from '../../common/model-displayer/GltfModelDisplayer';
import StlModelDisplayer from '../../common/model-displayer/StlModelDisplayer';
import AttestedStills from '../../common/attested-stills/AttestedStills';
import ModelFacts from '../../common/model-facts/ModelFacts';
import RequestView from './RequestView';
import FileHistory from '../../common/file-history/FileHistory';
import { BsPersonCircle } from 'react-icons/bs';

const DISPLAYERS = {
  gltf: GltfModelDisplayer,
  obj: ObjModelDisplayer,
  stl: StlModelDisplayer,
};

const SingleProductView = () => {
  const { productId } = useParams();

  const dispatch = useDispatch();
  const product = useSelector((state) => state.product.product);
  const productStatus = useSelector((state) => state.product.status);
  const error = useSelector((state) => state.product.error);

  const [chosenFileType, setChosenFileType] = useState(null);
  const [confirmingWithdrawal, setConfirmingWithdrawal] = useState(false);
  const [shownFormat, setShownFormat] = useState(null);
  const [note, setNote] = useState('');
  const replacing = useSelector((state) => state.product.replacing);
  const token = useSelector((state) => state.auth.token);
  const signedIn = Boolean(token);

  useEffect(() => {
    dispatch(fetchProductById(productId));
  }, [dispatch, productId]);

  const renderInProgress =
    product?.id === Number(productId) &&
    ['queued', 'rendering'].includes(product?.preview_status);

  // The render announces itself. Polling for it used to blank the page every
  // three seconds, because every request put the whole view back into loading.
  useEffect(() => {
    if (!renderInProgress) {
      return undefined;
    }

    const echo = createEcho(token);
    const channel = `products.${productId}`;

    echo.channel(channel).listen('.preview.render.finished', () => {
      dispatch(fetchProductById(productId));
    });

    return () => {
      echo.leave(channel);
      echo.disconnect();
    };
  }, [renderInProgress, dispatch, productId, token]);

  const formats = product?.formats ?? [];
  const selectedFileType = formats.includes(chosenFileType) ? chosenFileType : formats[0] ?? 'obj';

  // The stills gallery owns the format the buyer is looking at; the viewer's
  // own picker owns it in interactive mode.
  const measuredFormat =
    product?.preview_mode === 'attested_stills' ? shownFormat : selectedFileType;
  const measured = (product?.previews ?? []).find((one) => one.format === measuredFormat);

  if (productStatus === 'failed' && product?.id !== Number(productId)) {
    return <LoadError message={error} fallback="Could not load this product." />;
  }

  // Only before anything is on screen: a refetch while the page is up must not
  // replace it with a spinner.
  if (product?.id !== Number(productId)) {
    return (
      <div className="d-flex justify-content-center align-items-center">
        <LoadingSpinner />
      </div>
    );
  }

  const viewer = (Displayer, url) => (
    <ErrorBoundary FallbackComponent={ModelLoaderErrorFallback}>
      <Displayer fileUrl={url} />
    </ErrorBoundary>
  );

  let media;
  if (product.preview_mode === 'attested_stills') {
    media = <AttestedStills product={product} onFormat={setShownFormat} />;
  } else {
    const shown = product.preview_urls?.[selectedFileType]
      ? selectedFileType
      : Object.keys(DISPLAYERS).find((format) => product.preview_urls?.[format]);

    if (shown) {
      media = viewer(DISPLAYERS[shown], product.preview_urls[shown]);
    }
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

          {measured?.facts && (
            <div className={styles.section}>
              <ModelFacts
                facts={measured.facts}
                format={measured.format}
                agreement={product.format_agreement}
                missing={measured.missing}
                unusedImages={measured.unused_images}
              />

              <BundleContents bundle={measured.bundle} format={measured.format} />
            </div>
          )}

          {signedIn && measured?.facts?.bounds && measured.images?.length > 0 && (
            <div className={styles.section}>
              <RequestView
                product={product}
                format={measured.format}
                bounds={measured.facts.bounds}
                hasUvs={Boolean(measured.facts.uvs)}
                onPublished={() => dispatch(fetchProductById(productId))}
              />
            </div>
          )}

          {measured?.replaced?.length > 0 && (
            <div className={styles.section}>
              <FileHistory format={measured.format} replaced={measured.replaced} />
            </div>
          )}

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

          {product.product_status === 'owner' && measuredFormat && (
            <div className={styles.section}>
              <p className={styles.sectionLabel}>Replace the .{measuredFormat} file</p>
              <input
                className="form-control form-control-sm mb-2"
                placeholder="What changed? (optional)"
                value={note}
                maxLength={200}
                onInput={(event) => setNote(event.target.value)}
              />
              <input
                className="form-control form-control-sm"
                type="file"
                disabled={renderInProgress || replacing}
                onChange={(event) => {
                  const file = event.target.files?.[0];

                  if (file) {
                    dispatch(replaceFile({ productId: product.id, format: measuredFormat, file, note }));
                    setNote('');
                  }
                }}
              />
              {renderInProgress && (
                <p className={styles.locked}>Locked until the previews finish rendering.</p>
              )}
            </div>
          )}

          {product.product_status === 'owner' && (
            <div className={styles.section}>
              <p className={styles.sectionLabel}>Listing</p>
              {product.unlisted ? (
                <p className={styles.withdrawn}>Removed from sale.</p>
              ) : confirmingWithdrawal ? (
                <div className={styles.confirm}>
                  <button
                    type="button"
                    className="btn btn-danger btn-sm"
                    onClick={() => dispatch(withdrawProduct(product.id))}
                  >
                    Confirm removal
                  </button>
                  <button
                    type="button"
                    className="btn btn-outline-secondary btn-sm"
                    onClick={() => setConfirmingWithdrawal(false)}
                  >
                    Cancel
                  </button>
                </div>
              ) : (
                <button
                  type="button"
                  className="btn btn-outline-danger btn-sm"
                  onClick={() => setConfirmingWithdrawal(true)}
                >
                  Remove from sale
                </button>
              )}
            </div>
          )}
        </div>
      </div>
    </div>
  );
};

export default SingleProductView;
