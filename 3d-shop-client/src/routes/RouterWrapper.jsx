import React from 'react';
import { BrowserRouter as Router, useRoutes } from 'react-router-dom';
import CheckoutPage from '../components/pages/checkout-page/CheckoutPage';
import LoginPage from '../components/pages/login-page/LoginPage';
import SingleProductView from '../components/pages/model-page/SingleProductView';
import ModelsListPage from '../components/pages/models-list-page/ModelsListPage';
import RegisterPage from '../components/pages/register-page/RegisterPage';

const RoutesWrapper = () =>
  useRoutes([
    { path: '/login', element: <LoginPage /> },
    { path: '/register', element: <RegisterPage /> },
    { path: '/products', element: <ModelsListPage /> },
    { path: '/products/:pageNumber', element: <ModelsListPage /> },
    { path: '/product/:productId', element: <SingleProductView /> },
    { path: '/checkout', element: <CheckoutPage /> },
  ]);

export default RoutesWrapper;
