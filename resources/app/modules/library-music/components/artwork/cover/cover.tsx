import { Icon } from '@iconify/react';
import styles from './cover.module.scss';

interface CoverProps {
  imgSrc?: string;
  size?: number;
  interactive?: boolean;
}

export function Cover({imgSrc, size, interactive = false}: CoverProps) {

  return (
    <>
      <div style={{ height: `${size}px`, width: `${size}px` }}>
        {imgSrc
          ? <img src={imgSrc} height={size} width={size} className={`${interactive ? styles.interactive : undefined}`} style={{ borderRadius: '7px' }} alt=""/>
          : <FallbackImage size={size} interactive={interactive}/>
        }
      </div>
    </>
  );
}

function FallbackImage({size = 150, interactive = false}: { size?: number, interactive?: boolean }) {
  return (
    <div style={{height: size, width: size}} className={`${styles.coverFallBack} ${interactive ? styles.interactive : undefined}`}>
      <Icon icon="akar-icons:music-album-fill" height={size / 3} opacity={0.3}/>
    </div>
  );
}
