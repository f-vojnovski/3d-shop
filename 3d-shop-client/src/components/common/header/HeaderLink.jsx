import styles from './Header.module.css';
import { Link } from 'react-router-dom';

const HeaderLink = (props) => {
  return (
    <Link to={props.link}>
      <span className={`${styles.navigation_item} me-2`}>{props.children}</span>
    </Link>
  );
};

export default HeaderLink;
