import { useEffect, useRef, useState } from 'react';
import { useDispatch, useSelector } from 'react-redux';
import BoundsBoxDisplayer from '../../common/model-displayer/BoundsBoxDisplayer';
import Lightbox from '../../common/lightbox/Lightbox';
import {
  fetchCustomViews,
  publishCustomView,
  requestCustomView,
  selectCustomViews,
} from '../../../service/features/customViewSlice';
import styles from './RequestView.module.css';

const PASSES = [
  { key: 'shaded', label: 'Shaded' },
  { key: 'wireframe', label: 'Wireframe' },
  { key: 'checker', label: 'UV checker', needsUvs: true },
  { key: 'normals', label: 'Normals' },
];

const RequestView = ({ product, format, bounds, hasUvs = true, onPublished }) => {
  const dispatch = useDispatch();
  const views = useSelector(selectCustomViews);
  const requesting = useSelector((state) => state.customViews.requesting);
  const [open, setOpen] = useState(false);
  const [pass, setPass] = useState('shaded');
  const [shown, setShown] = useState(null);
  const probe = useRef(null);

  // Publishing queues a re-render, so the listing is refetched to pick it up.
  const publish = async (viewId) => {
    const result = await dispatch(publishCustomView({ productId: product.id, viewId }));

    if (publishCustomView.fulfilled.match(result)) {
      onPublished?.();
    }
  };

  useEffect(() => {
    dispatch(fetchCustomViews(product.id));
  }, [dispatch, product.id]);

  if (!bounds?.size) {
    return null;
  }

  const ask = () => {
    const aimed = probe.current?.();

    if (aimed) {
      dispatch(requestCustomView({
        productId: product.id,
        format,
        pass,
        camera: aimed.camera,
      }));
    }
  };

  return (
    <div className={styles.panel}>
      {!open && (
        <button type="button" className={styles.open} onClick={() => setOpen(true)}>
          Ask for another view
        </button>
      )}

      {open && (
        <>
          <p className={styles.label}>
            Aim at the outline and our server renders the .{format} file from there.
          </p>

          <div className={styles.stage}>
            <BoundsBoxDisplayer size={bounds.size} probeRef={probe} />
          </div>
        </>
      )}

      <div className={styles.controls} hidden={!open}>
        <div className={styles.passes} role="group" aria-label="Which pass">
          {PASSES.filter((one) => hasUvs || !one.needsUvs).map((one) => (
            <button
              key={one.key}
              type="button"
              aria-pressed={pass === one.key}
              className={pass === one.key ? styles.passOn : styles.pass}
              onClick={() => setPass(one.key)}
            >
              {one.label}
            </button>
          ))}
        </div>

        <button type="button" className={styles.ask} onClick={ask} disabled={requesting}>
          {requesting ? 'Sending' : 'Request render'}
        </button>
      </div>

      {views.length > 0 && (
        <ul className={styles.results}>
          {views.map((view) => (
            <li key={view.id}>
              {view.status === 'ready' && (
                <button
                  type="button"
                  className={styles.full}
                  title="Open at full size"
                  onClick={() => setShown(view)}
                >
                  <img src={view.url} alt={`${product.name}, ${view.pass} view you asked for`} />
                  <span>Full size</span>
                </button>
              )}
              {view.status === 'ready' && product.product_status === 'owner' && (
                <button
                  type="button"
                  className={styles.publish}
                  onClick={() => publish(view.id)}
                >
                  Use on the listing
                </button>
              )}
              {view.status === 'queued' && (
                <span className={styles.waiting}>
                  <span
                    className="spinner-border spinner-border-sm"
                    role="status"
                    aria-label="Rendering"
                  />
                </span>
              )}
              {view.status === 'failed' && <span className={styles.failed}>{view.error}</span>}
            </li>
          ))}
        </ul>
      )}

      {views.length > 0 && (
        <p className={styles.note}>These renders are kept for two hours.</p>
      )}

      {shown && (
        <Lightbox
          src={shown.url}
          alt={`${product.name}, ${shown.pass} view you asked for`}
          caption={`${shown.pass} · .${shown.format ?? format}`}
          onClose={() => setShown(null)}
        />
      )}
    </div>
  );
};

export default RequestView;
