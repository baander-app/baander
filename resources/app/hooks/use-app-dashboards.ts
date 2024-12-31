export function useAppDashboards() {
  const openNewTab = (path: string) => {
    window.open(`${import.meta.env.VITE_APP_URL}/${path}`, 'blank');
  };

  const openPulse = () => openNewTab('-/pulse');

  return {
    openPulse,
  };
}
