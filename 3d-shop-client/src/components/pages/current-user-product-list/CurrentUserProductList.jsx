import { useDispatch } from 'react-redux';
import { fetchProductsForCurrentUser } from '../../../service/features/productsSlice';
import ProductListingGrid from '../../common/products-listing/ProductListingGrid';

const CurrentUserProductList = () => {
  const dispatch = useDispatch();

  const fetchFunction = (pageNumber) => {
    dispatch(fetchProductsForCurrentUser(pageNumber));
  };
  return (
    <>
      <ProductListingGrid url="../my-products/" fetchFunction={fetchFunction} />
    </>
  );
};

export default CurrentUserProductList;
