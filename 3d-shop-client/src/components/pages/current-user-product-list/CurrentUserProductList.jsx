import { useDispatch } from 'react-redux';
import { fetchUploadedProductsForCurrentUser } from '../../../service/features/productsSlice';
import ProductListingGrid from '../../common/products-listing/ProductListingGrid';

const CurrentUserProductList = () => {
  const dispatch = useDispatch();

  const fetchFunction = (pageNumber) => {
    dispatch(fetchUploadedProductsForCurrentUser(pageNumber));
  };
  return (
    <>
      <ProductListingGrid url="../my-products/" fetchFunction={fetchFunction} />
    </>
  );
};

export default CurrentUserProductList;
