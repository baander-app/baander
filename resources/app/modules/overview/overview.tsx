import { Flex, Heading, Section } from '@radix-ui/themes';
import { useAlbumServiceGetApiLibrariesByLibraryAlbums } from '@/api-client/queries';
import { Carousel } from '@/ui/carousel/carousel.tsx';
import { ReactNode, useEffect, useState } from 'react';
import { Album } from '@/modules/library-music/components/album';

export interface OverviewProps {
  title: string;
}

export function Overview({title}: OverviewProps) {
  const { data } = useAlbumServiceGetApiLibrariesByLibraryAlbums({
    library: 'music',
    relations: 'cover,artists',
  });
  const [albums, setAlbums] = useState<ReactNode[]>([]);

  useEffect(() => {
    if (data) {
      const nodes = data.data.map(album => <Album key={album.slug} title={album.title} imgSrc={album.cover?.url} primaryArtist={album?.artists?.[0]?.name}/>);
      setAlbums(nodes);
    }
  }, [data]);

  return (
    <Flex direction="column" px="3">
      <Section title="Albums">
        <Heading mb="2">{title}</Heading>

        {albums.length > 0 && (
          <Carousel
            slides={albums}
            options={{
            slidesToScroll: 9,
          }}/>
        )}
      </Section>
    </Flex>
  );
}