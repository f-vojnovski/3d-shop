import { useSyncExternalStore } from 'react';
import { dismiss, getToasts, subscribe } from './toastStore';
import styles from './Toasts.module.css';

const Toasts = () => {
  const toasts = useSyncExternalStore(subscribe, getToasts, getToasts);

  if (toasts.length === 0) {
    return null;
  }

  return (
    <div className={styles.stack} role="status" aria-live="polite">
      {toasts.map((item) => (
        <div key={item.id} className={`${styles.toast} ${styles[item.tone]}`}>
          <span>{item.message}</span>
          <button
            type="button"
            className={styles.close}
            aria-label="Dismiss"
            onClick={() => dismiss(item.id)}
          >
            ×
          </button>
        </div>
      ))}
    </div>
  );
};

export default Toasts;
