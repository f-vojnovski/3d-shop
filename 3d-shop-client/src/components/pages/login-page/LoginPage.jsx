import { Button, input } from 'react-bootstrap';
import { useDispatch, useSelector } from 'react-redux';
import {
  getSanctumCookie,
  selectToken,
  selectUser,
} from '../../../service/features/authSlice';
import { useEffect } from 'react';
import { postLoginData } from '../../../service/features/authSlice';
import axios from 'axios';

const LoginPage = () => {
  axios.defaults.withCredentials = true;

  const http = axios.create({
    baseURL: 'http://localhost:8000',
    withCredentials: true,
  });

  useEffect(() => {
    authenticate();
  }, []);

  async function authenticate() {
    if (authStatus === 'idle') {
      http.get('/sanctum/csrf-cookie').then((res) => {
        let body = {
          name: 'peder',
          password: '123456'
        }
        dispatch(postLoginData(body));
      })    
    }
  }

  const dispatch = useDispatch();

  const user = useSelector(selectUser);
  const token = useSelector(selectToken);
  const authStatus = useSelector((state) => state.auth.status);

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
