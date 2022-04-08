import ProductOverview from "../../common/product-preview/ProductOverview";

const ModelsListPage = () => {
  return (
    <div className="container-fluid max-width-1600">
      <div className="row">
        <div className="col-sm-6 col-md-3 mb-2">
          <ProductOverview />
        </div>
        <div className="col-sm-6 col-md-3 mb-2">
          <ProductOverview />
        </div>
        <div className="col-sm-6 col-md-3 mb-2">
          <ProductOverview />
        </div>
        <div className="col-sm-6 col-md-3 mb-2">
          <ProductOverview />
        </div>
        <div className="col-sm-6 col-md-3 mb-2">
          <ProductOverview />
        </div>
        <div className="col-sm-6 col-md-3 mb-2">
          <ProductOverview />
        </div>
      </div>
    </div>
  );
};

export default ModelsListPage;
