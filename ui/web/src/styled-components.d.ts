import 'styled-components';

import type { Theme } from './shared/theme/theme.types';

declare module 'styled-components' {
  export interface DefaultTheme extends Theme {}
}
