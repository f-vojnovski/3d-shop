const WINDOW = 5;

const pagesAround = (current, pageCount) => {
  const start = Math.max(1, Math.min(current - Math.floor(WINDOW / 2), pageCount - WINDOW + 1));
  const end = Math.min(pageCount, start + WINDOW - 1);

  return Array.from({ length: end - start + 1 }, (_, index) => start + index);
};

const Pagination = ({ pageCount, currentPage, onPageChange }) => {
  if (!pageCount || pageCount < 2) {
    return null;
  }

  const page = Math.min(Math.max(currentPage, 1), pageCount);

  const step = (target) => () => {
    if (target !== page) {
      onPageChange(target);
    }
  };

  return (
    <nav className="d-flex justify-content-center" aria-label="Pages">
      <ul className="pagination mb-0">
        <li className={`page-item ${page === 1 ? 'disabled' : ''}`}>
          <button
            type="button"
            className="page-link"
            disabled={page === 1}
            onClick={step(page - 1)}
          >
            Previous
          </button>
        </li>

        {pagesAround(page, pageCount).map((number) => (
          <li key={number} className={`page-item ${number === page ? 'active' : ''}`}>
            <button
              type="button"
              className="page-link"
              aria-current={number === page ? 'page' : undefined}
              onClick={step(number)}
            >
              {number}
            </button>
          </li>
        ))}

        <li className={`page-item ${page === pageCount ? 'disabled' : ''}`}>
          <button
            type="button"
            className="page-link"
            disabled={page === pageCount}
            onClick={step(page + 1)}
          >
            Next
          </button>
        </li>
      </ul>
    </nav>
  );
};

export default Pagination;
