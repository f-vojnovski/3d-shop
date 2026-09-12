const SHOWN = 6;

/**
 * What a listing card can cycle through: the chosen thumbnail first, then the
 * seller's own pictures, then the attested renders. Deduplicated, because the
 * thumbnail is normally already one of the other two.
 */
export const cardImages = (product, limit = SHOWN) => {
  const urls = [
    product?.thumbnail_url,
    ...(product?.seller_images ?? []).map((image) => image.url),
    ...(product?.previews ?? []).flatMap((preview) =>
      (preview.images ?? []).map((image) => image.url)
    ),
  ];

  return [...new Set(urls.filter(Boolean))].slice(0, limit);
};
