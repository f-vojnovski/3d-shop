import { Link } from 'react-router-dom';
import { formatPrice } from '../../../service/util/formatPrice';
import styles from './ProductOverview.module.css';

const ProductOverview = ({ id, name, priceCents, thumbnailUrl }) => (
  <Link to={`/product/${id}`} className={styles.card}>
    <div className={styles.thumb}>
      {thumbnailUrl ? (
        <img src={thumbnailUrl} alt={`${name} thumbnail`} />
      ) : (
        <div className={styles.empty}>No preview</div>
      )}
    </div>
    <div className={styles.body}>
      <div className={styles.name}>{name}</div>
      <div className={styles.price}>${formatPrice(priceCents)}</div>
    </div>
  </Link>
);

export default ProductOverview;
