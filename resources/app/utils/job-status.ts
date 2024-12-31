export const statusToName = (value: number) => {
  switch (value) {
    case 0:
      return 'Running';
    case 1:
      return 'Success';
    case 2:
      return 'Failed';
    case 3:
      return 'Stale';
    case 4:
      return 'Queued';
    default:
      return 'Unknown';
  }
};

export const statusToColor = (value: number) => {
  switch (value) {
    case 0:
      return 'green';
    case 1:
      return 'blue';
    case 2:
      return 'red';
    case 3:
      return 'yellow';
    case 4:
      return 'indigo';
    default:
      return 'gray';
  }
};
