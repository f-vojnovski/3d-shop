const LoadError = ({ message, fallback = 'Something went wrong. Please try again.' }) => (
  <div className="d-flex justify-content-center align-items-center">
    <div className="row mt-3">
      <div className="col">
        <div className="alert alert-danger" role="alert">
          {message || fallback}
        </div>
      </div>
    </div>
  </div>
);

export default LoadError;
