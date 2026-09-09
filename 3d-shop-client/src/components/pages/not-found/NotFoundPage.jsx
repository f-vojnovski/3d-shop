import { Link } from 'react-router-dom';

const NotFoundPage = () => (
  <div className="container-fluid mt-5">
    <div className="row">
      <div className="col text-center">
        <h4>That page does not exist.</h4>
        <p className="mt-3">
          <Link to="/products">Browse the models</Link>
        </p>
      </div>
    </div>
  </div>
);

export default NotFoundPage;
