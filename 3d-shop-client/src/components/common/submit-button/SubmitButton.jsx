const SubmitButton = ({
  pending,
  disabled,
  children,
  pendingLabel = 'Working…',
  className = 'btn btn-primary',
  ...rest
}) => (
  <button type="button" className={className} disabled={pending || disabled} {...rest}>
    {pending && (
      <span className="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true" />
    )}
    {pending ? pendingLabel : children}
  </button>
);

export default SubmitButton;
