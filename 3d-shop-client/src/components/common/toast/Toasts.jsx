import { useDispatch, useSelector } from 'react-redux';
import { dismissToast, selectToasts } from '../../../service/features/toastSlice';
import styles from './Toasts.module.css';

const Toasts = () => {
  const dispatch = useDispatch();
  const toasts = useSelector(selectToasts);

  // Mounted even when empty: a live region that appears with its text is not
  // announced, because there was no region to observe a change in.
  return (
    <div className={styles.stack} role="status" aria-live="polite">
      {toasts.map((item) => (
        <div key={item.id} className={`${styles.toast} ${styles[item.tone]}`}>
          <span>{item.message}</span>
          <button
            type="button"
            className={styles.close}
            aria-label="Dismiss"
            onClick={() => dispatch(dismissToast(item.id))}
          >
            ×
          </button>
        </div>
      ))}
    </div>
  );
};

export default Toasts;
