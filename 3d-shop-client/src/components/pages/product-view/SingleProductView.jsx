import styles from './SingleProductView.module.css';
import { useParams } from 'react-router-dom';
import { useSelector, useDispatch } from 'react-redux';
import { useEffect, useState } from 'react';
import {
  fetchProductById,
  publishProduct,
  replaceFile,
  withdrawProduct,
} from '../../../service/features/productSlice';
import { createEcho } from '../../../service/realtime/echo';
import { ErrorBoundary } from 'react-error-boundary';
import ModelLoaderErrorFallback from './ModelLoaderErrorFallback';
import LoadingSpinner from '../../common/spinner/LoadingSpinner';
import BackLink from '../../common/back-link/BackLink';
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
import ClipPlayer from '../../common/clip-player/ClipPlayer';
import RequestView from './RequestView';
import FileHistory from '../../common/file-history/FileHistory';
import ReleaseStills from '../../common/release-stills/ReleaseStills';
import ThumbnailPicker from '../../common/thumbnail-picker/ThumbnailPicker';
import { orderedReleases } from '../../../service/util/releases';
import SubmitButton from '../../common/submit-button/SubmitButton';
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
  const [viewingRelease, setViewingRelease] = useState(null);
  const replacing = useSelector((state) => state.product.replacing);
  const publishing = useSelector((state) => state.product.publishing);
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
  // The seller's own pictures are not a format, but what is measured below is
  // a fact about the product rather than about which tab is open. Letting this
  // go null emptied half the rail whenever the gallery switched.
  const measuredFormat =
    (product?.preview_mode === 'attested_stills' ? shownFormat : selectedFileType)
    ?? product?.previews?.[0]?.format
    ?? null;
  const measured = (product?.previews ?? []).find((one) => one.format === measuredFormat);
  const releases = orderedReleases(measured?.replaced);
  // A release index belongs to one format's history, so it is remembered with
  // the format it was chosen from and ignored under any other.
  const viewingIndex =
    viewingRelease?.format === measuredFormat ? viewingRelease.index : null;
  const release = viewingIndex === null ? null : releases[viewingIndex];

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

  // A chosen release owns the stage: the point of opening one is to see it at
  // the size the file on sale gets, not as a strip of thumbnails in the rail.
  if (release) {
    media = (
      <ReleaseStills
        release={release}
        format={measured.format}
        productName={product.name}
      />
    );
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
      <BackLink to="/products">Back to models</BackLink>

      <div className={styles.layout}>
        <div className={styles.media}>{media}</div>

        <div className={styles.rail}>
          <div className={styles.seller}>
            <BsPersonCircle /> Seller #{product.user_id}
          </div>

          <h1 className={styles.title}>{product.name}</h1>
          <div className={styles.price}>${formatPrice(product.price_cents)}</div>

          {release ? (
            <p className={styles.historyNote}>
              You are looking at an older release. Go back to the latest to buy or change
              this product.
            </p>
          ) : (
            <AddToCartButton product={product} />
          )}

          {product.description && <p className={styles.description}>{product.description}</p>}

          {(release?.facts ?? measured?.facts) && (
            <div className={styles.section}>
              <ModelFacts
                facts={release?.facts ?? measured.facts}
                format={measured.format}
                label={release ? 'Measured from that older .' + measured.format + ' file' : null}
                agreement={release ? null : product.format_agreement}
                missing={release ? null : measured.missing}
                unusedImages={release ? null : measured.unused_images}
              />

              {!release && <BundleContents bundle={measured.bundle} format={measured.format} />}
            </div>
          )}

          {!release && product.clips?.length > 0 && (
            <div className={styles.section}>
              <ClipPlayer clips={product.clips} />
            </div>
          )}

          {releases.length > 0 && (
            <div className={styles.section}>
              <FileHistory
                format={measured.format}
                releases={releases}
                viewing={viewingIndex}
                onView={(index) =>
                  setViewingRelease(
                    index === null ? null : { format: measuredFormat, index }
                  )
                }
              />
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

          {!release && formats.length > 1 && product.preview_mode !== 'attested_stills' && (
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

          {!release && downloads.length > 0 && (
            <div className={styles.section}>
              <p className={styles.sectionLabel}>Your files</p>
              <div className={styles.downloads}>{downloads}</div>
            </div>
          )}

          {!release && signedIn && measured?.facts?.bounds && measured.images?.length > 0 && (
            <div className={styles.section}>
              <RequestView
                product={product}
                format={measured.format}
                bounds={measured.facts.bounds}
                proxy={measured.proxy}
                hasUvs={Boolean(measured.facts.uvs)}
                onPublished={() => dispatch(fetchProductById(productId))}
              />
            </div>
          )}

          {!release && product.product_status === 'owner' && (
            <div className={styles.section}>
              <p className={styles.sectionLabel}>Thumbnails</p>
              <ThumbnailPicker product={product} />
            </div>
          )}

          {!release && product.product_status === 'owner' && measuredFormat && (
            <div className={styles.section}>
              <p className={styles.sectionLabel}>Update .{measuredFormat} file</p>
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

          {!release && product.product_status === 'owner' && (
            <div className={styles.section}>
              <p className={styles.sectionLabel}>Listing</p>
              {product.listing_status !== 'live' && (
                <p className={styles.withdrawn}>
                  {product.listing_status === 'draft'
                    ? 'Not published yet. Nobody can see this but you.'
                    : 'Removed from sale. Nobody can see this but you.'}
                </p>
              )}

              {product.listing_status !== 'live' ? (
                <SubmitButton
                  className="btn btn-primary btn-sm"
                  pending={publishing}
                  pendingLabel="Publishing…"
                  onClick={() => dispatch(publishProduct(product.id))}
                >
                  {product.listing_status === 'draft' ? 'Publish listing' : 'Put back on sale'}
                </SubmitButton>
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
