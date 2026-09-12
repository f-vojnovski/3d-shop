import { useEffect } from 'react';
import { useDispatch, useSelector } from 'react-redux';
import { fetchSales } from '../../../service/features/salesSlice';
import LoadingSpinner from '../../common/spinner/LoadingSpinner';
import LoadError from '../../common/load-error/LoadError';
import { formatPrice } from '../../../service/util/formatPrice';
import { Link } from 'react-router-dom';
import styles from './SalesListing.module.css';

const COLUMNS = ['Id', 'Buyer', 'Product', 'Earnings'];

const SalesListing = () => {
  const sales = useSelector((state) => state.sales.sales);
  const salesStatus = useSelector((state) => state.sales.status);
  const error = useSelector((state) => state.sales.error);

  const dispatch = useDispatch();

  useEffect(() => {
    if (salesStatus === 'idle') {
      dispatch(fetchSales());
    }
  }, [salesStatus, dispatch]);

  if (salesStatus === 'loading') {
    return (
      <div className="page-shell">
        <LoadingSpinner />
      </div>
    );
  }

  if (salesStatus === 'failed') {
    return (
      <div className="page-shell">
        <LoadError message={error} fallback="Could not load your sales." />
      </div>
    );
  }

  const earnings = sales.reduce((total, sale) => total + sale.price_cents, 0);
  const models = new Set(sales.map((sale) => sale.product_id)).size;

  return (
    <div className="page-shell">
      <h1 className={styles.heading}>My sales</h1>

      <div className={styles.figures}>
        <div className={styles.figure}>
          <span className={styles.figureLabel}>Earnings</span>
          <span className={styles.figureValue}>${formatPrice(earnings)}</span>
        </div>
        <div className={styles.figure}>
          <span className={styles.figureLabel}>Sales</span>
          <span className={styles.figureValue}>{sales.length}</span>
        </div>
        <div className={styles.figure}>
          <span className={styles.figureLabel}>Models sold</span>
          <span className={styles.figureValue}>{models}</span>
        </div>
      </div>

      <div className={styles.tableWrap}>
        <table className="table table-hover">
          <thead className="table-dark">
            <tr>
              {COLUMNS.map((column) => (
                <th key={column}>{column}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {sales.length === 0 ? (
              <tr>
                <td className={styles.empty} colSpan={COLUMNS.length}>
                  No sales yet. When someone buys one of your models, it appears here with
                  the buyer and what you earned.
                </td>
              </tr>
            ) : (
              sales.map((sale) => (
                <tr key={sale.id}>
                  <td>{sale.id}</td>
                  <td>{sale.buyer_name}</td>
                  <td>
                    <Link
                      to={'/product/' + sale.product_id}
                      target="_blank"
                      rel="noopener noreferrer"
                    >
                      {sale.product_name}
                    </Link>
                  </td>
                  <td>${formatPrice(sale.price_cents)}</td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
};

export default SalesListing;
