const ProductOverview = (props) => {
  return (
    <div>
      <div className="card max-width-350">
        <div className="d-flex justify-content-center m-1">
          <img
            src="https://placeholder.pics/svg/300/E8E8E8/000000/Thumbnail%20not%20available"
            className="img-fluid"
            alt="Thumbnail not availbale"
          ></img>
        </div>
        <div className="ms-2 font-size-small">
          <div>{props.name}</div>
          <div className="bolded-label">{props.price}</div>
        </div>
      </div>
    </div>
  );
};

export default ProductOverview;
