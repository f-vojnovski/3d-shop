import { useEffect } from 'react';
import { useDispatch, useSelector } from 'react-redux';
import { fetchSales } from '../../../service/features/salesSlice';
import LoadingSpinner from '../../common/spinner/LoadingSpinner';
import { Link } from 'react-router-dom';

const SalesListing = () => {
  const sales = useSelector((state) => state.sales.sales);
  const salesStatus = useSelector((state) => state.sales.status);

  const dispatch = useDispatch();

  useEffect(() => {
    if (salesStatus === 'idle') {
      dispatch(fetchSales());
    }
  }, [salesStatus, dispatch]);

  let content;

  if (salesStatus === 'loading') {
    content = (
      <div className="d-flex justify-content-center align-items-center">
        <LoadingSpinner />
      </div>
    );
  }

  if (salesStatus === 'error') {
    content = <h5>Error while fetching sales!</h5>;
  }

  if (sales.length === 0 && salesStatus === 'succeeded') {
    content = <h5>For now, you have not made any sales.</h5>;
  }

  if (salesStatus === 'succeeded') {
    let mappedSales = sales.map((sale) => (
      <tr key={sale.id}>
        <td>{sale.id}</td>
        <td>{sale.buyer_name}</td>
        <td>
          {' '}
          <Link
            to={'/product/' + sale.product_id}
            target="_blank"
            rel="noopener noreferrer"
          >
            {sale.product_name}{' '}
          </Link>
        </td>
        <td>${sale.price}</td>
      </tr>
    ));

    content = (
      <div className="container-fluid mt-4">
        <div className="table-container">
          <table className="table table-hover">
            <thead className="table-dark">
              <tr>
                <th>Id</th>
                <th>Buyer</th>
                <th>Product</th>
                <th>Earnings</th>
              </tr>
            </thead>
            <tbody>{mappedSales}</tbody>
          </table>
        </div>
      </div>
    );
  }

  return <>{content}</>;
};

export default SalesListing;
