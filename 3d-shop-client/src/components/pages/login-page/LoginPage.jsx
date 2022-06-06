import { Button, input } from 'react-bootstrap';
import { useDispatch, useSelector } from 'react-redux';
import { logoutUser, selectToken, selectUser } from '../../../service/features/authSlice';
import { useEffect, useState } from 'react';
import { postLoginData } from '../../../service/features/authSlice';
import LoadingSpinner from '../../common/spinner/LoadingSpinner';
import { toast, Zoom } from 'react-toastify';
import { getRequest } from '../../../service/api/axiosClient';
import axios from 'axios';

const LoginPage = () => {
  const auth = useSelector((state) => state.auth);
  const authStatus = useSelector((state) => state.auth.status);
  const error = useSelector((state) => state.auth.error);

  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');

  const dispatch = useDispatch();

  useEffect(() => {
    if (authStatus === 'succeeded') {
      toast.success('You are logged in!');
    }
  }, [authStatus]);

  let onLoginClicked = () => {
    let body = {
      name: username,
      password: password,
    };
    dispatch(postLoginData(body));
  };

  let onLogoutClicked = () => {
    dispatch(logoutUser(auth.token));
  }

  let content;

  let defaultState = (
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
            <input
              className="form-control"
              value={username}
              onInput={(e) => setUsername(e.target.value)}
            />
          </div>
        </div>

        <div className="row mt-1">
          <div className="col">
            <label>Password</label>
            <input
              type="password"
              className="form-control"
              value={password}
              onInput={(e) => setPassword(e.target.value)}
            />
          </div>
        </div>

        <div className="row mt-3">
          <div className="col">
            <Button
              onClick={() => {
                onLoginClicked();
              }}
            >
              Login
            </Button>
          </div>
        </div>

        {error && (
          <div className="row mt-3">
            <div className="col">
              <div className="alert alert-danger" role="alert">
                {error}
              </div>
            </div>
          </div>
        )}
      </div>
    </div>
  );

  if (authStatus === 'loading') {
    content = (
      <div>
        <LoadingSpinner />
      </div>
    );
  }

  if (authStatus === 'succeeded') {
    content = (
      <div className="row">
        <div className="col">
          <h4>You are already logged in!</h4>
          <button onClick={() => {onLogoutClicked()}}>
            Logout
          </button>
        </div>
      </div>
    );
  }

  if (!content) {
    content = defaultState;
  }

  return <div>{content}</div>;
};

export default LoginPage;
