import { Link } from 'react-router-dom';

const LandingPage = () => {
  return (
    <div className="container-fluid">
      <div className="row mt-3">
        <div className="col">
          <h1>Trading with 3d models made easier than ever!</h1>
        </div>
      </div>

      <div className="row">
        <div className="col">
          <Link className="text-link" to="/products">
            Browse products
          </Link>
        </div>
      </div>
    </div>
  );
};

export default LandingPage;
