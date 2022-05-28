import { Button, input } from 'react-bootstrap';
import { useDispatch, useSelector } from 'react-redux';
import { selectToken, selectUser } from '../../../service/features/authSlice';
import { useEffect } from 'react';
import { postLoginData } from '../../../service/features/authSlice';

const LoginPage = () => {
  const dispatch = useDispatch();

  const user = useSelector(selectUser);
  const token = useSelector(selectToken);
  const authStatus = useSelector((state) => state.auth.status);
  
  useEffect(() => {
    if (authStatus === 'idle') {
      dispatch(postLoginData('peder', '123456'))
    }
  }, []);

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
