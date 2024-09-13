export function formatDuration(duration: number | string) {
  if (typeof duration === 'string') {
    duration = Number(duration);
  }

  const min = Math.floor(duration / 60);
  const sec = Math.floor(duration - min * 60);
  // format - mm:ss
  return [min, sec].map((n) => (n < 10 ? '0' + n : n)).join(':');
}