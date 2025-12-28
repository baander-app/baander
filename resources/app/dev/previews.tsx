import { ComponentPreview, Previews } from '@react-buddy/ide-toolbox';
import { PaletteTree } from './palette.tsx';
import App from '@/app/App.tsx';

const ComponentPreviews = () => {
  return (
    <Previews palette={<PaletteTree/>}>
      <ComponentPreview path="/App">
        <App />
      </ComponentPreview>
      <ComponentPreview path="/PaletteTree">
        <PaletteTree/>
      </ComponentPreview>
    </Previews>
  );
};

export default ComponentPreviews;