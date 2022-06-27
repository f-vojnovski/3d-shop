import { useState } from 'react';
import { Button } from 'react-bootstrap';
import { useDispatch, useSelector } from 'react-redux';
import { useNavigate } from 'react-router-dom';
import authSlice, { postRegisterData } from '../../../service/features/authSlice';
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

  useEffect(
    () => {
      if (user) {
        navigate('/');
      }

      if (authStatus === 'succeeded') {
        toast.success('You are now logged in!');
        navigate('/');
      }
    },
    user,
    authStatus
  );

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
            <Button onClick={() => onRegisterButtonClick()}>Register</Button>
          </div>
        </div>
      </div>
    </div>
  );
};

export default RegisterPage;
