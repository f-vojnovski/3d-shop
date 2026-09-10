import ProductOverview from '../../common/product-preview/ProductOverview';
import { useDispatch, useSelector } from 'react-redux';
import { selectAllProducts } from '../../../service/features/productsSlice';
import { useEffect } from 'react';
import LoadingSpinner from '../../common/spinner/LoadingSpinner';
import LoadError from '../../common/load-error/LoadError';
import Pagination from '../pagination/Pagination';
import { useNavigate, useParams } from 'react-router-dom';
import { clearProductsStatus } from '../../../service/features/productsSlice';

const ProductListingGrid = (props) => {
  const { fetchFunction } = props;
  const params = useParams();
  let pageNumber = params.pageNumber;

  if (pageNumber == null) {
    pageNumber = 1;
  }

  const products = useSelector(selectAllProducts);
  const productsStatus = useSelector((state) => state.products.status);
  const error = useSelector((state) => state.products.error);

  const dispatch = useDispatch();

  let content;

  useEffect(() => {
    if (productsStatus === 'idle') {
      fetchFunction(pageNumber);
    }
  }, [productsStatus, fetchFunction, pageNumber]);

  useEffect(() => {
    dispatch(clearProductsStatus());
  }, [dispatch, pageNumber]);

  let navigate = useNavigate();

  const handlePageChange = (requestedPage) => navigate(`${props.url}${requestedPage}`);

  if (productsStatus === 'loading') {
    content = (
      <div className="d-flex justify-content-center align-items-center">
        <LoadingSpinner />
      </div>
    );
  }

  if (productsStatus === 'failed') {
    content = <LoadError message={error} fallback="Could not load products." />;
  }

  if (productsStatus === 'succeeded') {
    const renderedProducts = products.products.map((product) => (
      <div className="col-sm-6 col-md-3 mb-2" key={product.id}>
        <ProductOverview
          id={product.id}
          name={product.name}
          description={product.description}
          priceCents={product.price_cents}
          thumbnailUrl={product.thumbnail_url}
        ></ProductOverview>
      </div>
    ));

    content = (
      <>
        <div className="row">{renderedProducts}</div>
        <div className="row mt-3 mb-3">
          <div className="col">
            <Pagination
              pageCount={products.pageCount}
              currentPage={Number(pageNumber)}
              onPageChange={handlePageChange}
            />
          </div>
        </div>
      </>
    );
  }

  return <div className="page-shell">{content}</div>;
};

export default ProductListingGrid;
