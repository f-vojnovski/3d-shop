import { useCallback } from 'react';
import { useDispatch } from 'react-redux';
import { fetchUploadedProductsForCurrentUser } from '../../../service/features/productsSlice';
import ProductListingGrid from '../../common/products-listing/ProductListingGrid';

const CurrentUserProductList = () => {
  const dispatch = useDispatch();

  // The grid fetches whenever this changes, so it must not change per render.
  const fetchFunction = useCallback((pageNumber) => {
    dispatch(fetchUploadedProductsForCurrentUser(pageNumber));
  }, [dispatch]);

  return (
    <>
      <ProductListingGrid
        url="../my-products/"
        fetchFunction={fetchFunction}
        emptyMessage="You have not uploaded a model yet."
      />
    </>
  );
};

export default CurrentUserProductList;
