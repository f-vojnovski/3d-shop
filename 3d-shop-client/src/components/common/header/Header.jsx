import styles from './Header.module.css';
import HeaderContentUnauthenticated from './HeaderUnauthenticated';
import HeaderContentAuthenticated from './HeaderContentAuthenticated';
import { useSelector } from 'react-redux';
import { Link } from 'react-router-dom';

const Header = () => {
  const auth = useSelector((state) => state.auth);

  let content;
  if (auth.user == null) {
    content = <HeaderContentUnauthenticated />;
  } else {
    content = <HeaderContentAuthenticated />;
  }

  return (
    <nav className="navbar navbar-dark bg-dark mb-2 ps-3 pe-3 pt-3 pb-3 d-flex">
      <span className={styles.title}>
        {' '}
        <Link className="text-link" to="/">
          3D MARKETPLACE
        </Link>
      </span>
      <span className="justify-content-end">{content}</span>
    </nav>
  );
};

export default Header;
