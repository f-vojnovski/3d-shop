const LoadingSpinner = () => {
  return (
    <div className="centered-item">
      <div
        className="spinner-border"
        role="status"
        style={{ width: '4rem', height: '4rem' }}
      >
        <span className="sr-only"></span>
      </div>
    </div>
  );
};

export default LoadingSpinner;
