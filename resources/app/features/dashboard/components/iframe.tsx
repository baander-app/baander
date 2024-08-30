import styles from './iframe.module.scss';

export interface IframeProps {
  routeName: string;
}
export function Iframe({routeName}: IframeProps) {
  return (
    <div className={styles.iframeContainer}>
      <iframe
        className={styles.iframe}
        src={route(routeName)}
      ></iframe>
    </div>
  );
}