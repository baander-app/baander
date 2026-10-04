import 'styled-components';

import type { Theme } from './shared/theme/theme.types';

declare module 'styled-components' {
  export interface DefaultTheme {
    readonly colors: Theme['colors'];
    readonly radii: Theme['radii'];
    readonly spacing: Theme['spacing'];
    readonly typography: Theme['typography'];
    readonly durations: Theme['durations'];
    readonly _meta: Theme['_meta'];
  }
}
