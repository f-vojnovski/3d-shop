const DownloadButton = (props) => {
  return (
    <a href={props.link} target="_blank" rel="noopener noreferrer" download>
      {props.text}
    </a>
  );
};
export default DownloadButton;
