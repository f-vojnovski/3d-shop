import ProductOverview from '../../common/product-preview/ProductOverview';
import { useDispatch, useSelector } from 'react-redux';
import { selectAllProducts } from '../../../service/features/productsSlice';
import { useEffect } from 'react';
import LoadingSpinner from '../../common/spinner/LoadingSpinner';
import ReactPaginate from 'react-paginate';
import { useNavigate, useParams } from 'react-router-dom';
import { clearProductsStatus } from '../../../service/features/productsSlice';

const ProductListingGrid = (props) => {
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
      props.fetchFunction(pageNumber);
    }
  }, [productsStatus, dispatch, pageNumber]);

  useEffect(() => {
    dispatch(clearProductsStatus());
  }, [pageNumber]);

  let navigate = useNavigate();

  const handlePageClick = (event) => {
    let requestedPage = event.selected + 1;
    navigate(`${props.url}${requestedPage}`);
  };

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

    content = (
      <>
        <div className="row">{renderedProducts}</div>
        <div className="row mt-3 mb-3">
          <div className="col d-flex justify-content-center">
            <ReactPaginate
              breakLabel="..."
              nextLabel="next >"
              initialPage={parseInt(pageNumber) - 1}
              disableInitialCallback={true}
              onPageChange={handlePageClick}
              pageRangeDisplayed={5}
              pageCount={products.pageCount}
              previousLabel="< previous"
              renderOnZeroPageCount={null}
              breakClassName={'page-item'}
              breakLinkClassName={'page-link'}
              containerClassName={'pagination'}
              pageClassName={'page-item'}
              pageLinkClassName={'page-link'}
              previousClassName={'page-item'}
              previousLinkClassName={'page-link'}
              nextClassName={'page-item'}
              nextLinkClassName={'page-link'}
              activeClassName={'active'}
            />
          </div>
        </div>
      </>
    );
  }

  return <div className="container-fluid max-width-1600">{content}</div>;
};

export default ProductListingGrid;
