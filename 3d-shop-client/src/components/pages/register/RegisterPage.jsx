import { useState } from 'react';
import { useDispatch, useSelector } from 'react-redux';
import { useNavigate } from 'react-router-dom';
import { postRegisterData } from '../../../service/features/authSlice';
import { toast } from 'react-toastify';
import { useEffect } from 'react';
import SubmitButton from '../../common/submit-button/SubmitButton';
import {
  email as validateEmail,
  firstErrors,
  minLength,
  required,
  same,
} from '../../../service/util/validate';

const RegisterPage = () => {
  const dispatch = useDispatch();

  const navigate = useNavigate();

  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [passwordConfirm, setPasswordConfirm] = useState('');
  const [errors, setErrors] = useState({});

  const user = useSelector((state) => state.auth.user);
  const authStatus = useSelector((state) => state.auth.status);
  const error = useSelector((state) => state.auth.error);

  useEffect(() => {
    if (user) {
      navigate('/');
      return;
    }

    if (authStatus === 'succeeded') {
      toast.success(`Welcome, ${name}. Your account is ready and you are signed in.`);
      navigate('/products');
    }
  }, [user, authStatus, name, navigate]);

  useEffect(() => {
    if (authStatus === 'failed') {
      toast.error(error || 'Could not create your account.');
    }
  }, [authStatus, error]);

  const onRegisterButtonClick = () => {
    const found = firstErrors({
      email: required(email, 'An email address') ?? validateEmail(email),
      name: required(name, 'A username'),
      password: required(password, 'A password') ?? minLength(password, 8, 'The password'),
      passwordConfirm: same(password, passwordConfirm, 'The passwords do not match.'),
    });

    setErrors(found);

    if (Object.keys(found).length > 0) {
      return;
    }

    dispatch(
      postRegisterData({
        name,
        email,
        password,
        password_confirmation: passwordConfirm,
      })
    );
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
            {errors.email && <div className="field-error">{errors.email}</div>}
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
            {errors.name && <div className="field-error">{errors.name}</div>}
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
            {errors.password && <div className="field-error">{errors.password}</div>}
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
            {errors.passwordConfirm && <div className="field-error">{errors.passwordConfirm}</div>}
          </div>
        </div>

        <div className="row mt-3">
          <div className="col">
            <SubmitButton
              pending={authStatus === 'loading'}
              onClick={() => onRegisterButtonClick()}
            >
              Register
            </SubmitButton>
          </div>
        </div>
      </div>
    </div>
  );
};

export default RegisterPage;
