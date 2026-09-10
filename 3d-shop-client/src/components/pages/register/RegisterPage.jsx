import { useState } from 'react';
import { useDispatch, useSelector } from 'react-redux';
import { useNavigate } from 'react-router-dom';
import { postRegisterData } from '../../../service/features/authSlice';
import { toast } from 'react-toastify';
import { useEffect } from 'react';

const RegisterPage = () => {
  const dispatch = useDispatch();

  const navigate = useNavigate();

  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState();
  const [passwordConfirm, setPasswordConfirm] = useState('');

  const user = useSelector((state) => state.auth.user);
  const authStatus = useSelector((state) => state.auth.status);
  const error = useSelector((state) => state.auth.error);

  useEffect(() => {
    if (user) {
      navigate('/');
      return;
    }

    if (authStatus === 'succeeded') {
      toast.success('You are now logged in!');
      navigate('/');
    }
  }, [user, authStatus, navigate]);

  useEffect(() => {
    if (authStatus === 'failed') {
      toast.error(error || 'Could not create your account.');
    }
  }, [authStatus, error]);

  const onRegisterButtonClick = () => {
    const body = {
      name: name,
      email: email,
      password: password,
      password_confirmation: passwordConfirm,
    };

    dispatch(postRegisterData(body));
  };

  return (
    <div>
      <div className="form-shell">
        <div className="row mt-1">
          <div className="col">
            <h1>Registration Form</h1>
          </div>
        </div>
        <div className="row mt-1">
          <div className="col">
            <label>Email address</label>
            <input
              type="email"
              className="form-control"
              placeholder="example@example.com"
              onInput={(e) => setEmail(e.target.value)}
            />
          </div>
        </div>

        <div className="row mt-1">
          <div className="col">
            <label>Name</label>
            <input
              type=""
              className="form-control"
              placeholder="John Doe"
              onInput={(e) => setName(e.target.value)}
            />
          </div>
        </div>

        <div className="row mt-1">
          <div className="col">
            <label>Password</label>
            <input
              type="password"
              className="form-control"
              onInput={(e) => setPassword(e.target.value)}
            />
          </div>
        </div>

        <div className="row mt-1">
          <div className="col">
            <label>Confirm password</label>
            <input
              type="password"
              className="form-control"
              onInput={(e) => setPasswordConfirm(e.target.value)}
            />
          </div>
        </div>

        <div className="row mt-3">
          <div className="col">
            <button type="button" className="btn btn-primary" onClick={() => onRegisterButtonClick()}>
              Register
            </button>
          </div>
        </div>
      </div>
    </div>
  );
};

export default RegisterPage;
