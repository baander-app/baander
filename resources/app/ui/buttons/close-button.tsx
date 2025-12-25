import { Button } from '@radix-ui/themes';
import { Iconify } from '@/app/ui/icons/iconify.tsx';

export interface CloseButtonProps {
  onClick?: () => void;
}

export function CloseButton({onClick}: CloseButtonProps) {

  return (
    <Button onClick={onClick}>
      <Iconify icon="ion:close" width="20" height="20" />
    </Button>
  )
}