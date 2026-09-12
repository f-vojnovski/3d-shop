import ProductOverview from '../../common/product-preview/ProductOverview';
import { cardImages } from '../../../service/util/cardImages';
import { useSelector } from 'react-redux';
import { selectAllProducts } from '../../../service/features/productsSlice';
import { useEffect } from 'react';
import LoadingSpinner from '../../common/spinner/LoadingSpinner';
import LoadError from '../../common/load-error/LoadError';
import Pagination from '../pagination/Pagination';
import { useNavigate, useParams } from 'react-router-dom';
import styles from './ProductListingGrid.module.css';

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

  let content;

  // Fetch when the page changes. Driving this by writing 'idle' back into the
  // shared status meant two effects racing over one value.
  useEffect(() => {
    fetchFunction(pageNumber);
  }, [fetchFunction, pageNumber]);

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

  if (productsStatus === 'succeeded' && products.products.length === 0) {
    content = (
      <div className={styles.empty}>
        <p>{props.emptyMessage ?? 'Nothing is listed yet.'}</p>
      </div>
    );
  }

  if (productsStatus === 'succeeded' && products.products.length > 0) {
    const renderedProducts = products.products.map((product) => (
      <div className="col-sm-6 col-md-3 mb-2" key={product.id}>
        <ProductOverview
          id={product.id}
          name={product.name}
          priceCents={product.price_cents}
          images={cardImages(product)}
          status={product.listing_status}
        />
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
