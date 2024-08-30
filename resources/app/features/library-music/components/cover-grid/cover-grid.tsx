import React from 'react';

import styles from './cover-grid.module.scss';

interface CoverGridProps {
  children: React.ReactNode;
  style?: React.CSSProperties;
}

export function CoverGrid({ children, style }: CoverGridProps) {

  return (
    <div className={styles.coverGrid} style={style}>
      {children}
    </div>
  )
}
