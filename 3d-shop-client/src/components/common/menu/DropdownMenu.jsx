import { useCallback, useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import styles from './DropdownMenu.module.css';

export const MenuItem = ({ children, onClick, danger }) => (
  <button
    type="button"
    role="menuitem"
    className={`${styles.item} ${danger ? styles.danger : ''}`}
    onClick={onClick}
  >
    {children}
  </button>
);

export const MenuLink = ({ to, children }) => (
  <Link role="menuitem" className={styles.item} to={to}>
    {children}
  </Link>
);

export const MenuDivider = () => <div className={styles.divider} />;

export const MenuHeading = ({ children }) => (
  <div className={styles.heading} role="presentation">
    {children}
  </div>
);

export const MenuRow = ({ label, amount }) => (
  <div className={styles.row} role="presentation">
    <span className={styles.amount}>{amount}</span>
    <span className="text-truncate">{label}</span>
  </div>
);

const DropdownMenu = ({ label, badge, ariaLabel, children }) => {
  const [open, setOpen] = useState(false);
  const container = useRef(null);
  const panel = useRef(null);
  const trigger = useRef(null);

  const items = useCallback(
    () => Array.from(panel.current?.querySelectorAll('[role="menuitem"]') ?? []),
    []
  );

  const focusItem = useCallback(
    (index) => {
      const all = items();

      if (all.length > 0) {
        all[(index + all.length) % all.length].focus();
      }
    },
    [items]
  );

  const close = ({ restoreFocus }) => {
    setOpen(false);

    if (restoreFocus) {
      trigger.current?.focus();
    }
  };

  useEffect(() => {
    if (!open) {
      return undefined;
    }

    focusItem(0);

    const closeOnOutside = (event) => {
      if (!container.current?.contains(event.target)) {
        setOpen(false);
      }
    };

    document.addEventListener('mousedown', closeOnOutside);

    return () => document.removeEventListener('mousedown', closeOnOutside);
  }, [open, focusItem]);

  const onPanelKeyDown = (event) => {
    const all = items();
    const at = all.indexOf(document.activeElement);

    if (event.key === 'Escape') {
      close({ restoreFocus: true });
    } else if (event.key === 'ArrowDown') {
      event.preventDefault();
      focusItem(at + 1);
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      focusItem(at - 1);
    } else if (event.key === 'Home') {
      event.preventDefault();
      focusItem(0);
    } else if (event.key === 'End') {
      event.preventDefault();
      focusItem(all.length - 1);
    } else if (event.key === 'Tab') {
      setOpen(false);
    }
  };

  return (
    <div className={styles.container} ref={container}>
      <button
        ref={trigger}
        type="button"
        className={styles.trigger}
        aria-label={ariaLabel}
        aria-haspopup="menu"
        aria-expanded={open}
        onClick={() => setOpen((current) => !current)}
        onKeyDown={(event) => {
          if (event.key === 'ArrowDown' && !open) {
            event.preventDefault();
            setOpen(true);
          }
        }}
      >
        {label}
        {badge > 0 && <span className={styles.badge}>{badge}</span>}
      </button>

      {open && (
        <div
          ref={panel}
          className={styles.panel}
          role="menu"
          aria-label={ariaLabel}
          onKeyDown={onPanelKeyDown}
          onClick={() => close({ restoreFocus: false })}
        >
          {children}
        </div>
      )}
    </div>
  );
};

export default DropdownMenu;
