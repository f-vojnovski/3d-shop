import { useParams, useRoutes } from 'react-router-dom';
import CheckoutPage from '../components/pages/checkout/CheckoutPage';
import OrderComplete from '../components/pages/checkout/OrderComplete';
import CurrentUserProductList from '../components/pages/current-user-product-list/CurrentUserProductList';
import LoginPage from '../components/pages/login/LoginPage';
import ProductUploadPage from '../components/pages/product-upload/ProductUpload';
import SingleProductView from '../components/pages/product-view/SingleProductView';
import ModelsListPage from '../components/pages/products-list/ModelsListPage';
import RegisterPage from '../components/pages/register/RegisterPage';
import SalesListing from '../components/pages/sales-list/SalesListing';
import LandingPage from '../components/pages/landing/LandingPage';
import PurchasedProducstPage from '../components/pages/purchased-products/PurchasedProductsPage';
import NotFoundPage from '../components/pages/not-found/NotFoundPage';
import RequireAuth from './RequireAuth';

const guarded = (element) => <RequireAuth>{element}</RequireAuth>;

/**
 * One product page per product. Without the key, following a link from one
 * product to another re-renders the page rather than remounting it, and its
 * state carries over: the buyer lands on B still looking at an older release of
 * A, with the add-to-cart button replaced by a note about it.
 */
const KeyedProduct = () => <SingleProductView key={useParams().productId} />;

const RoutesWrapper = () =>
  useRoutes([
    { path: '/login', element: <LoginPage /> },
    { path: '/register', element: <RegisterPage /> },
    { path: '/products', element: <ModelsListPage /> },
    { path: '/products/:pageNumber', element: <ModelsListPage /> },
    { path: '/product/:productId', element: <KeyedProduct /> },
    { path: '/checkout', element: guarded(<CheckoutPage />) },
    { path: '/checkout/complete', element: guarded(<OrderComplete />) },
    { path: '/upload', element: guarded(<ProductUploadPage />) },
    { path: '/my-products', element: guarded(<CurrentUserProductList />) },
    { path: '/my-products/:pageNumber', element: guarded(<CurrentUserProductList />) },
    { path: '/my-sales', element: guarded(<SalesListing />) },
    { path: '/purchases', element: guarded(<PurchasedProducstPage />) },
    { path: '/purchases/:pageNumber', element: guarded(<PurchasedProducstPage />) },
    { path: '/', element: <LandingPage /> },
    { path: '*', element: <NotFoundPage /> },
  ]);

export default RoutesWrapper;
