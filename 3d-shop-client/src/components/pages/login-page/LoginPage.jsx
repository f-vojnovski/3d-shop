import { Button, input } from 'react-bootstrap';

const LoginPage = () => {
  return (
    <div>
      <div className="container-fluid my-auto form_max_width">
        <div className="row mt-1">
          <div className="col">
            <h1>Login Form</h1>
          </div>
        </div>

        <div className="row mt-1">
          <div className="col">
            <label>Username</label>
            <input className="form-control" />
          </div>
        </div>

        <div className="row mt-1">
          <div className="col">
            <label>Password</label>
            <input type="password" className="form-control" />
          </div>
        </div>

        <div className="row mt-3">
          <div className="col">
            <Button>Login</Button>
          </div>
        </div>
      </div>
    </div>
  );
};

export default LoginPage;
