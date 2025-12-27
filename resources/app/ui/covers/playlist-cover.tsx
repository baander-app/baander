import { Iconify } from '@/app/ui/icons/iconify';
import { memo } from 'react';
import styles from './playlist-cover.module.scss';

interface PlaylistCoverProps {
  size?: number;
  coverUrl?: string | null;
  albumCovers?: (string | null | undefined)[];
  isSmart?: boolean;
  className?: string;
}

export const PlaylistCover = memo(({ size = 160, coverUrl, albumCovers, isSmart, className }: PlaylistCoverProps) => {
  const containerStyle = {
    width: `${size}px`,
    height: `${size}px`,
  };

  // If custom cover exists, use it
  if (coverUrl) {
    return (
      <div className={`${styles.cover} ${className || ''}`} style={containerStyle}>
        <img src={coverUrl} alt="Playlist cover" className={styles.image} />
      </div>
    );
  }

  // If we have 4+ album covers, create mosaic
  if (albumCovers && albumCovers.length >= 4) {
    const covers = albumCovers.slice(0, 4);
    return (
      <div className={`${styles.mosaic} ${className || ''}`} style={containerStyle}>
        {covers.map((cover, index) => (
          <div
            key={index}
            className={styles.quarter}
            style={{
              backgroundImage: cover ? `url(${cover})` : undefined,
              backgroundPosition: 'center',
              backgroundSize: 'cover',
              backgroundRepeat: 'no-repeat',
            }}
          />
        ))}
      </div>
    );
  }

  // Default cover with gradient
  return (
    <div className={`${styles.default} ${className || ''}`} style={containerStyle}>
      {isSmart && (
        <div className={styles.smartIcon}>
          <Iconify icon="carbon:rule" width={32} height={32} />
        </div>
      )}
      <Iconify icon="ph:music-notes-simple-bold" width={40} height={40} className={styles.defaultIcon} />
    </div>
  );
});

PlaylistCover.displayName = 'PlaylistCover';
