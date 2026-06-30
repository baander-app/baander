import * as React from 'react';
import styled, { css } from 'styled-components';
import { Slider as SliderPrimitive } from 'radix-ui';
import { focusVisibleRing } from '@/shared/theme';

const StyledRoot = styled(SliderPrimitive.Root)`
  position: relative;
  display: flex;
  width: 100%;
  touch-action: none;
  user-select: none;
  align-items: center;
  &[data-disabled] { opacity: 0.5; }
  &[data-orientation="vertical"] { height: 100%; min-height: 10rem; width: auto; flex-direction: column; }
`;

const StyledTrack = styled(SliderPrimitive.Track)`
  position: relative;
  flex-grow: 1;
  overflow: hidden;
  border-radius: 9999px;
  background-color: var(--color-muted);
  &[data-orientation="horizontal"] { height: 0.25rem; width: 100%; }
  &[data-orientation="vertical"] { height: 100%; width: 0.25rem; }
`;

const StyledRange = styled(SliderPrimitive.Range)`
  position: absolute;
  background-color: var(--color-primary);
  user-select: none;
  &[data-orientation="horizontal"] { height: 100%; }
  &[data-orientation="vertical"] { width: 100%; }
`;

const StyledThumb = styled(SliderPrimitive.Thumb)`
  position: relative;
  display: block;
  width: 0.75rem;
  height: 0.75rem;
  flex-shrink: 0;
  border-radius: 9999px;
  border: 1px solid var(--color-ring);
  background-color: white;
  ${focusVisibleRing}
  transition: color var(--duration-hover) ease-out, box-shadow var(--duration-hover) ease-out;
  user-select: none;
  &::after { content: ''; position: absolute; inset: -0.5rem; }
  &:hover { box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-ring) 50%, transparent); }
  &:active { box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-ring) 50%, transparent); }
  &:disabled { pointer-events: none; opacity: 0.5; }
`;

function Slider({
  defaultValue,
  value,
  min = 0,
  max = 100,
  ...props
}: React.ComponentProps<typeof SliderPrimitive.Root>) {
  const _values = React.useMemo(
    () =>
      Array.isArray(value)
        ? value
        : Array.isArray(defaultValue)
          ? defaultValue
          : [min, max],
    [value, defaultValue, min, max]
  );

  return (
    <StyledRoot
      data-slot="slider"
      defaultValue={defaultValue}
      value={value}
      min={min}
      max={max}
      {...props}
    >
      <StyledTrack data-slot="slider-track">
        <StyledRange data-slot="slider-range" />
      </StyledTrack>
      {Array.from({ length: _values.length }, (_, index) => (
        <StyledThumb data-slot="slider-thumb" key={index} />
      ))}
    </StyledRoot>
  );
}

export { Slider };
