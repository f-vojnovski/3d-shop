import { useSelector } from 'react-redux';
import styles from './Header.module.css';

const HeaderContentAuthenticated = () => {
  const auth = useSelector((state) => state.auth);

  return <div className={`${styles.navigation} me-2`}>{auth.user.name}</div>;
};

export default HeaderContentAuthenticated;
