import ProductOverview from '../../common/product-preview/ProductOverview';
import { useSelector, useDispatch } from 'react-redux';
import {
  fetchProducts,
  selectAllProducts,
} from '../../../service/features/productsSlice';
import { useEffect } from 'react';
import LoadingSpinner from '../../common/spinner/LoadingSpinner';

const ModelsListPage = () => {
  const dispatch = useDispatch();
  const products = useSelector(selectAllProducts);

  const productsStatus = useSelector((state) => state.products.status);
  const error = useSelector((state) => state.products.error);

  let content;

  useEffect(() => {
    if (productsStatus === 'idle') {
      dispatch(fetchProducts());
    }
  }, [productsStatus, dispatch]);

  if (productsStatus === 'loading') {
    content = (
      <div className="d-flex justify-content-center align-items-center">
        <LoadingSpinner />
      </div>
    );
  }

  if (productsStatus === 'succeeded') {
    const renderedProducts = products.products.map((product) => (
      <div className="col-sm-6 col-md-3 mb-2" key={product.id}>
        <ProductOverview
          id={product.id}
          name={product.name}
          description={product.description}
          price={product.price}
        ></ProductOverview>
      </div>
    ));

    content = <div className="row">{renderedProducts}</div>;
  }

  return <div className="container-fluid max-width-1600">{content}</div>;
};

export default ModelsListPage;
