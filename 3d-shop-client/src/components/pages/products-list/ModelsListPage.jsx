import { useCallback } from 'react';
import { useDispatch } from 'react-redux';
import { fetchProducts } from '../../../service/features/productsSlice';
import ProductListingGrid from '../../common/products-listing/ProductListingGrid';

const ModelsListPage = () => {
  const dispatch = useDispatch();

  // The grid fetches whenever this changes, so it must not change per render.
  const fetchFunction = useCallback((pageNumber) => {
    dispatch(fetchProducts(pageNumber));
  }, [dispatch]);

  return (
    <>
      <ProductListingGrid
        url="../products/"
        fetchFunction={fetchFunction}
        emptyMessage="No models are listed yet."
      />
    </>
  );
};

export default ModelsListPage;
