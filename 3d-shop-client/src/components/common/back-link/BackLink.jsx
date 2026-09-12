import { Link } from 'react-router-dom';
import styles from './BackLink.module.css';

const BackLink = ({ to, children }) => (
  <Link to={to} className={styles.back}>
    <span aria-hidden="true">&#8592;</span>
    {children}
  </Link>
);

export default BackLink;
