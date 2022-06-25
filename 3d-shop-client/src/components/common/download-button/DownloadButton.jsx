import { Link } from 'react-router-dom';

const DownloadButton = (props) => {
  return (
    <Link to={props.link} target="_blank" download>
      {props.text}
    </Link>
  );
};
export default DownloadButton;
