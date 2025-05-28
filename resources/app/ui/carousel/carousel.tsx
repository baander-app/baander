import React, { ReactNode } from 'react';
import { EmblaOptionsType } from 'embla-carousel';
import useEmblaCarousel from 'embla-carousel-react';
import { NextButton, PrevButton, usePrevNextButtons } from '@/ui/carousel/components/arrow-buttons.tsx';
import './carousel.scss';

type PropType = {
  slides: ReactNode[]
  options?: EmblaOptionsType
}

export const Carousel: React.FC<PropType> = (props) => {
  const { slides, options } = props;
  const [emblaRef, emblaApi] = useEmblaCarousel(options);

  const {
    prevBtnDisabled,
    nextBtnDisabled,
    onPrevButtonClick,
    onNextButtonClick,
  } = usePrevNextButtons(emblaApi);

  return (
    <section className="embla">
      <div className="embla__viewport" ref={emblaRef}>
        <div className="embla__container">
          {slides.map((node, index) => (
            <div className="embla__slide" key={index}>
              {node}
            </div>
          ))}
        </div>
      </div>

      <div className="embla__controls">
        <div className="embla__buttons">
          <PrevButton onClick={onPrevButtonClick} disabled={prevBtnDisabled}/>
          <NextButton onClick={onNextButtonClick} disabled={nextBtnDisabled}/>
        </div>
      </div>
    </section>
  );
};

