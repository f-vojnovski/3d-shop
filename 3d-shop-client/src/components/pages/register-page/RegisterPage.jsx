import { Button } from 'react-bootstrap';

const RegisterPage = () => {
  return (
    <div>
      <div className="container-fluid my-auto form_max_width">
        <div className="row mt-1">
          <div className="col">
            <h1>Registertration Form</h1>
          </div>
        </div>
        <div className="row mt-1">
          <div className="col">
            <label>Email address</label>
            <input
              type="email"
              className="form-control"
              placeholder="example@example.com"
            />
          </div>
        </div>

        <div className="row mt-1">
          <div className="col">
            <label>Name</label>
            <input type="" className="form-control" placeholder="John Doe" />
          </div>
        </div>

        <div className="row mt-1">
          <div className="col">
            <label>Password</label>
            <input type="password" className="form-control" />
          </div>
        </div>

        <div className="row mt-1">
          <div className="col">
            <label>Confirm password</label>
            <input type="password" className="form-control" />
          </div>
        </div>

        <div className="row mt-3">
          <div className="col">
            <Button>Register</Button>
          </div>
        </div>
      </div>
    </div>
  );
};

export default RegisterPage;
