import styles from './Header.module.css';
import HeaderContentUnauthenticated from './HeaderUnauthenticated';
import HeaderContentAuthenticated from './HeaderContentAuthenticated';
import { useSelector } from 'react-redux';
import { Link } from 'react-router-dom';

const Mark = () => (
  <svg className={styles.mark} width="22" height="22" viewBox="0 0 32 32" aria-hidden="true">
    <path d="M16 5l9 5.2v10.6L16 26l-9-5.2V10.2z" fill="none" stroke="currentColor" strokeWidth="2" />
    <path d="M16 15.4l9-5.2M16 15.4V26M16 15.4l-9-5.2" stroke="currentColor" strokeWidth="2" />
  </svg>
);

const Header = () => {
  const user = useSelector((state) => state.auth.user);

  return (
    <nav className={styles.bar}>
      <Link className={styles.brand} to="/">
        <Mark />
        3D MARKETPLACE
      </Link>

      <div className={styles.nav}>
        <Link to="/products">Browse</Link>
        {user != null && <Link to="/upload">Publish</Link>}
      </div>

      <div className={styles.right}>
        {user == null ? <HeaderContentUnauthenticated /> : <HeaderContentAuthenticated />}
      </div>
    </nav>
  );
};

export default Header;
