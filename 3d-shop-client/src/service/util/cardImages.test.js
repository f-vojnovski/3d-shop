import { cardImages } from './cardImages';

describe('cardImages', () => {
  it('takes every picture the seller put on the card', () => {
    const urls = cardImages({
      thumbnails: [{ url: '/a.png' }, { url: '/b.png' }, { url: '/c.png' }],
    });

    expect(urls).toEqual(['/a.png', '/b.png', '/c.png']);
  });

  /** An older listing carries one picture and no list. */
  it('falls back to the single thumbnail when there is no list', () => {
    expect(cardImages({ thumbnail_url: '/only.png' })).toEqual(['/only.png']);
  });

  it('answers with nothing rather than a list of nothings', () => {
    expect(cardImages({})).toEqual([]);
    expect(cardImages(null)).toEqual([]);
    expect(cardImages({ thumbnails: [], thumbnail_url: null })).toEqual([]);
  });

  /** A card is a card, not a gallery: the rest are on the product page. */
  it('stops at six so one listing cannot fill the row', () => {
    const many = Array.from({ length: 20 }, (_, at) => ({ url: `/${at}.png` }));

    expect(cardImages({ thumbnails: many })).toHaveLength(6);
  });
});
