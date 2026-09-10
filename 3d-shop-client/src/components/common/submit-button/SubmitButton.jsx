const SubmitButton = ({ pending, children, className = 'btn btn-primary', ...rest }) => (
  <button type="button" className={className} disabled={pending} {...rest}>
    {pending && (
      <span className="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true" />
    )}
    {pending ? 'Working…' : children}
  </button>
);

export default SubmitButton;
