import { Iconify } from '@/ui/icons/iconify.tsx';
import { Button } from '@radix-ui/themes';

export interface PowerProps {
  [key: string]: any;
  isActive: boolean;
  handleOnPowerClick: () => void;
}

export function Power(props: PowerProps) {
  const { handleOnPowerClick, isActive, ...rest } = props;
  return (
    <div {...rest}>
      <Button
        onClick={() => handleOnPowerClick()}
        // @ts-expect-error
        c={isActive ? 'orange' : 'gray'}
      >
        <Iconify icon="ph:power" fontSize={16} />
      </Button>
    </div>
  );
}