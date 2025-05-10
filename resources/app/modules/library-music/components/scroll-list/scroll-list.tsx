import React, { useEffect, useState } from 'react';
import { Virtuoso } from 'react-virtuoso';
import { Separator, Text } from '@radix-ui/themes';
import styles from './scroll-list.module.scss';

export interface ScrollListItem {
  label: string;
  key?: string;
}

interface ScrollListProps extends React.HTMLProps<HTMLDivElement> {
  listItems: ScrollListItem[];
  totalCount: number;
  header?: string;
  onItemPress?: (item?: ScrollListItem) => void;
  style?: React.CSSProperties;
}

export function ScrollList({ header, listItems, totalCount, onItemPress, style }: ScrollListProps) {
  const [activeIndex, setActiveIndex] = useState(0);

  useEffect(() => {
    if (listItems && listItems.length > 0 && listItems[0].key !== '*') {
      listItems.splice(0, 0, { label: 'Any', key: '*' });
    }
  }, [listItems]);

  return (
    <div className={styles.scrollList}>
      {header && (
        <>
          <Text size="3" className={styles.title}>{header}</Text>
          <Separator/>
        </>
      )}

      <Virtuoso<ScrollListItem>
        totalCount={totalCount}
        style={style}
        components={{
          Scroller,
        }}
        itemContent={(index) => {
          return (
            <div
              className={styles.listItem}
              style={{ backgroundColor: activeIndex === index ? '#ccc' : 'unset' }}

              onClick={() => {
                setActiveIndex(index);
                const item = listItems[index];
                if (onItemPress) {
                  if (item.key === '*') {
                    onItemPress();
                  } else {
                    onItemPress(item);
                  }
                }
              }}
            >
              <Text size="3">{listItems[index].label}</Text>
            </div>
          );
        }}
      />
    </div>
  );
}

interface ScrollerProps {
  style: React.CSSProperties;

  [key: string]: any;
}

const Scroller = React.forwardRef<HTMLDivElement, ScrollerProps>(({ style, ...props }, ref) => {
  // an alternative option to assign the ref is
  // <div ref={(r) => ref.current = r}>
  return <div className={styles.scrollbar} style={{ ...style }} ref={ref} {...props} />;
});