export const statusToColor = (value: string) => {
  switch (value) {
    case 'succeeded':
      return 'green';
    case 'running':
      return 'blue';
    case 'failed':
      return 'red';
    case 'stale':
      return 'yellow';
    case 'queued':
      return 'indigo';
    default:
      return 'gray';
  }
};
