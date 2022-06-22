import { useDispatch } from 'react-redux';
import { fetchPurchasedProductsForCurrentUser } from '../../../service/features/productsSlice';
import ProductListingGrid from '../../common/products-listing/ProductListingGrid';

const PurchasedProducstPage = () => {
  const dispatch = useDispatch();

  const fetchFunction = (pageNumber) => {
    dispatch(fetchPurchasedProductsForCurrentUser(pageNumber));
  };
  return (
    <>
      <ProductListingGrid url="../purchases/" fetchFunction={fetchFunction} />
    </>
  );
};

export default PurchasedProducstPage;
