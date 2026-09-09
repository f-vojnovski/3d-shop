// Both failure events must reject: without them a FileReader error (usually the
// file moved since it was picked) leaves the promise unsettled and the picker
// silently does nothing.
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
