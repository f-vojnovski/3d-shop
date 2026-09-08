// Reads a picked file into memory as a data URI, so a model can be previewed
// before it is uploaded. Browsers do not allow reading it straight off disk.
//
// FileReader can fail, most commonly when the file has been moved or renamed
// since the user picked it. Without an error path the promise never settles and
// the picker silently does nothing, so both failure events reject here.
export const fileToDataUri = (file) =>
  new Promise((resolve, reject) => {
    const reader = new FileReader();

    reader.onload = (event) => {
      resolve(event.target.result);
    };

    reader.onerror = () => {
      reject(reader.error || new Error('The file could not be read.'));
    };

    reader.onabort = () => {
      reject(new Error('Reading the file was cancelled.'));
    };

    reader.readAsDataURL(file);
  });
