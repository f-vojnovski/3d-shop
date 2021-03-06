import React from 'react';
import { BrowserRouter as Router, useRoutes } from 'react-router-dom';
import CheckoutPage from '../components/pages/checkout/CheckoutPage';
import CurrentUserProductList from '../components/pages/current-user-product-list/CurrentUserProductList';
import LoginPage from '../components/pages/login/LoginPage';
import ProductUploadPage from '../components/pages/product-upload/ProductUpload';
import SingleProductView from '../components/pages/product-view/SingleProductView';
import ModelsListPage from '../components/pages/products-list/ModelsListPage';
import RegisterPage from '../components/pages/register/RegisterPage';
import SalesListing from '../components/pages/sales-list/SalesListing';
import LandingPage from '../components/pages/landing/LandingPage';
import PurchasedProducstPage from '../components/pages/purchased-products/PurchasedProductsPage';

const RoutesWrapper = () =>
  useRoutes([
    { path: '/login', element: <LoginPage /> },
    { path: '/register', element: <RegisterPage /> },
    { path: '/products', element: <ModelsListPage /> },
    { path: '/products/:pageNumber', element: <ModelsListPage /> },
    { path: '/product/:productId', element: <SingleProductView /> },
    { path: '/checkout', element: <CheckoutPage /> },
    { path: '/upload', element: <ProductUploadPage /> },
    { path: '/my-products', element: <CurrentUserProductList /> },
    { path: '/my-products/:pageNumber', element: <CurrentUserProductList /> },
    { path: '/my-sales', element: <SalesListing /> },
    { path: '/purchases', element: <PurchasedProducstPage /> },
    { path: '/purchases/:pageNumber', element: <PurchasedProducstPage /> },
    { path: '/', element: <LandingPage /> },
  ]);

export default RoutesWrapper;
