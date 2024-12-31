import { Icon, IconProps } from '@iconify/react';


export interface IconifyProps extends IconProps {

}
export function Iconify({...props}: IconifyProps) {
  // @ts-ignore
  return <Icon {...props} />
}

