import { useEffect } from 'react';
import { useDispatch, useSelector } from 'react-redux';
import { Link } from 'react-router-dom';
import { fetchProducts, selectAllProducts } from '../../../service/features/productsSlice';
import ProductOverview from '../../common/product-preview/ProductOverview';
import LoadingSpinner from '../../common/spinner/LoadingSpinner';
import styles from './LandingPage.module.css';

const SHOWN = 4;

const claims = [
  ['Rendered here, not uploaded', 'Every image on a listing is drawn by this server from the model that is for sale.'],
  ['Measured, not typed', 'Polygon counts, real-world size and whether UVs exist are read out of the file itself.'],
  ['Checkable', 'Each image links to the camera, the renderer and the checksums it was made from.'],
];

const LandingPage = () => {
  const dispatch = useDispatch();
  const products = useSelector(selectAllProducts);
  const status = useSelector((state) => state.products.status);

  useEffect(() => {
    dispatch(fetchProducts(1));
  }, [dispatch]);

  const listed = products.products ?? [];
  const latest = listed.slice(0, SHOWN);

  // The seller's own thumbnail would be the obvious thing to put here and the
  // one image on the site that does not support the sentence beside it.
  const showpiece = listed
    .flatMap((product) => (product.previews ?? []).map((preview) => ({ product, preview })))
    .find(({ preview }) => preview.status === 'ready' && (preview.images ?? []).length > 0);

  return (
    <div className={styles.page}>
      <section className={styles.hero}>
        <div className={styles.pitch}>
          <h1>Every picture here was made from the file you download.</h1>
          <p>
            Sellers choose the angle. The server opens the model that is for sale, puts a camera
            exactly where they asked, and renders the image itself — then records what it used.
          </p>
          <Link className={styles.cta} to="/products">
            Browse models
          </Link>
        </div>

        {showpiece && (
          <figure className={styles.proof}>
            <Link to={`/product/${showpiece.product.id}`}>
              <img src={showpiece.preview.images[0].url} alt={showpiece.product.name} />
            </Link>
            <figcaption>
              {showpiece.product.name} — rendered here from the .{showpiece.preview.format} file
              on sale.
            </figcaption>
          </figure>
        )}
      </section>

      <section className={styles.claims}>
        {claims.map(([title, body]) => (
          <div key={title}>
            <h2>{title}</h2>
            <p>{body}</p>
          </div>
        ))}
      </section>

      <section className={styles.latest}>
        <h2>On sale now</h2>

        {status === 'loading' && latest.length === 0 && (
          <div className={styles.waiting}>
            <LoadingSpinner />
          </div>
        )}

        {status === 'succeeded' && latest.length === 0 && (
          <p className={styles.none}>Nothing is listed yet.</p>
        )}

        <div className={styles.grid}>
          {latest.map((product) => (
            <ProductOverview
              key={product.id}
              id={product.id}
              name={product.name}
              priceCents={product.price_cents}
              thumbnailUrl={product.thumbnail_url}
            />
          ))}
        </div>
      </section>
    </div>
  );
};

export default LandingPage;
