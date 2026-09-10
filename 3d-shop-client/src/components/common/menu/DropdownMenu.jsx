import { useEffect, useRef, useState } from 'react';
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

export const MenuHeading = ({ children }) => <div className={styles.heading}>{children}</div>;

export const MenuRow = ({ label, amount }) => (
  <div className={styles.row}>
    <span className={styles.amount}>{amount}</span>
    <span className="text-truncate">{label}</span>
  </div>
);

const DropdownMenu = ({ label, badge, ariaLabel, children }) => {
  const [open, setOpen] = useState(false);
  const container = useRef(null);

  useEffect(() => {
    if (!open) {
      return undefined;
    }

    const closeOnOutside = (event) => {
      if (!container.current?.contains(event.target)) {
        setOpen(false);
      }
    };

    const closeOnEscape = (event) => {
      if (event.key === 'Escape') {
        setOpen(false);
      }
    };

    document.addEventListener('mousedown', closeOnOutside);
    document.addEventListener('keydown', closeOnEscape);

    return () => {
      document.removeEventListener('mousedown', closeOnOutside);
      document.removeEventListener('keydown', closeOnEscape);
    };
  }, [open]);

  return (
    <div className={styles.container} ref={container}>
      <button
        type="button"
        className={styles.trigger}
        aria-label={ariaLabel}
        aria-haspopup="menu"
        aria-expanded={open}
        onClick={() => setOpen((current) => !current)}
      >
        {label}
        {badge > 0 && <span className={styles.badge}>{badge}</span>}
      </button>

      {open && (
        <div className={styles.panel} role="menu" onClick={() => setOpen(false)}>
          {children}
        </div>
      )}
    </div>
  );
};

export default DropdownMenu;
