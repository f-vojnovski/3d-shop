import { Link } from 'react-router-dom';

const DownloadButton = (props) => {
  console.log(props);

  return (
    <a href={props.link} target="_blank" download>
      {props.text}
    </a>
  );
};
export default DownloadButton;
