import { Flex, Heading, Section } from '@radix-ui/themes';
import { Carousel } from '@/app/ui/carousel/carousel.tsx';
import { ReactNode, useEffect, useState } from 'react';
import { Album } from '@/app/modules/library-music/components/album';
import { useAlbumsIndex } from '@/app/libs/api-client/gen/endpoints/album/album.ts';

export interface OverviewProps {
  title: string;
}

export function Overview({title}: OverviewProps) {
  const { data } = useAlbumsIndex('music', {
    relations: 'cover,artists',
  });
  const [albums, setAlbums] = useState<ReactNode[]>([]);

  useEffect(() => {
    if (data) {
      const nodes = data.data.map(album => <Album key={album.publicId} title={album.title} imgSrc={album.cover?.url} primaryArtist={album?.artists?.[0]?.name}/>);
      setAlbums(nodes);
    }
  }, [data]);

  return (
    <Flex direction="column" px="3">
      <Section title="Albums">
        <Heading mb="2">{title}</Heading>

        <Carousel slides={albums} />
      </Section>
    </Flex>
  );
}
