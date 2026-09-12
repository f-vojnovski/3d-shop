const SHOWN = 6;

export const cardImages = (product, limit = SHOWN) => {
  const urls = (product?.thumbnails ?? []).map((image) => image.url);

  return (urls.length > 0 ? urls : [product?.thumbnail_url]).filter(Boolean).slice(0, limit);
};
