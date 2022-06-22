import { useDispatch } from 'react-redux';
import { fetchProducts } from '../../../service/features/productsSlice';
import ProductListingGrid from '../../common/products-listing/ProductListingGrid';

const ModelsListPage = () => {
  const dispatch = useDispatch();

  const fetchFunction = (pageNumber) => {
    dispatch(fetchProducts(pageNumber));
  };
  return (
    <>
      <ProductListingGrid url="../products/" fetchFunction={fetchFunction} />
    </>
  );
};

export default ModelsListPage;
