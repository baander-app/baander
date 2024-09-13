import { useEcho } from '@/providers/echo-provider.tsx';
import styles from './socket-connection.module.scss'
import { useEffect, useState } from 'react';

export function SocketConnection() {
  const { connectionState } = useEcho();
  const [cssClass, setCssClass] = useState<string | undefined>()

  useEffect(() => {
    if (connectionState) {
      switch (connectionState) {
        case 'initialized':
          setCssClass(styles.initialized);
          break;
        case 'connecting':
          setCssClass(styles.connecting);
          break;
        case 'connected':
          setCssClass(styles.connected);
          break;
        default:
          setCssClass(undefined);
          break;
      }
    }
  }, [connectionState]);

  return (
    <>
      <div className={`${styles.socketConnection} ${cssClass}`} title={connectionState} />
    </>
  )
}