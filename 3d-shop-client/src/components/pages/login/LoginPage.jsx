import { useDispatch, useSelector } from 'react-redux';
import { useEffect, useState } from 'react';
import { postLoginData } from '../../../service/features/authSlice';
import LoadingSpinner from '../../common/spinner/LoadingSpinner';
import { toast } from '../../common/toast/toastStore';
import { useNavigate } from 'react-router-dom';
import SubmitButton from '../../common/submit-button/SubmitButton';
import { firstErrors, required } from '../../../service/util/validate';

const LoginPage = () => {
  const authStatus = useSelector((state) => state.auth.status);
  const error = useSelector((state) => state.auth.error);

  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [errors, setErrors] = useState({});

  const dispatch = useDispatch();

  const navigate = useNavigate();

  useEffect(() => {
    if (authStatus === 'succeeded') {
      toast.success('You are logged in!');
      navigate('/');
    }
  }, [authStatus, navigate]);

  useEffect(() => {
    if (authStatus === 'failed') {
      toast.error(error || 'Could not sign you in.');
    }
  }, [authStatus, error]);

  const onLoginClicked = () => {
    const found = firstErrors({
      username: required(username, 'A username'),
      password: required(password, 'A password'),
    });

    setErrors(found);

    if (Object.keys(found).length > 0) {
      return;
    }

    dispatch(postLoginData({ name: username, password }));
  };

  let content;

  let defaultState = (
    <div>
      <div className="form-shell">
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
            {errors.username && <div className="field-error">{errors.username}</div>}
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
            {errors.password && <div className="field-error">{errors.password}</div>}
          </div>
        </div>

        <div className="row mt-3">
          <div className="col">
            <SubmitButton pending={authStatus === 'loading'} onClick={() => onLoginClicked()}>
              Login
            </SubmitButton>
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
          <p>Now redirecting...</p>
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
