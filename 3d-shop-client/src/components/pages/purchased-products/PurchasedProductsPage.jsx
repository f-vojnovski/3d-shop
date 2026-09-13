import { useCallback } from 'react';
import { useDispatch } from 'react-redux';
import { fetchPurchasedProductsForCurrentUser } from '../../../service/features/productsSlice';
import ProductListingGrid from '../../common/products-listing/ProductListingGrid';

const PurchasedProducstPage = () => {
  const dispatch = useDispatch();

  // The grid fetches whenever this changes, so it must not change per render.
  const fetchFunction = useCallback((pageNumber) => {
    dispatch(fetchPurchasedProductsForCurrentUser(pageNumber));
  }, [dispatch]);

  return (
    <>
      <ProductListingGrid
        url="../purchases/"
        fetchFunction={fetchFunction}
        emptyMessage="You have not bought anything yet."
      />
    </>
  );
};

export default PurchasedProducstPage;
