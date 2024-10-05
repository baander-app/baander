import { Action, combineSlices, configureStore, ThunkAction } from '@reduxjs/toolkit';
import { persistReducer, persistStore } from 'redux-persist';
import storage from 'redux-persist/lib/storage';
import { authSlice } from '@/store/users/auth-slice.ts';
import { equalizerSlice } from '@/store/audio/equalizer.ts';
import { musicPlayerSlice } from '@/store/music/music-player-slice.ts';
import { userTableSlice } from '@/store/ui/user-table-slice.ts';

const rootReducer = combineSlices(
  authSlice,
  equalizerSlice,
  musicPlayerSlice,
  userTableSlice,
);
export type RootState = ReturnType<typeof rootReducer>

const persistConfig = {
  key: 'root',
  storage,
};
const persistedReducer = persistReducer(persistConfig, rootReducer);// as unknown as typeof rootReducer;

export const makeStore = () => {
  const store = configureStore({
    devTools: true,
    reducer: persistedReducer,
    middleware: (getDefaultMiddleware) =>
      getDefaultMiddleware({
        serializableCheck: false,
      }),
  });

  return store;
};

export const store = makeStore();

export const persistor = persistStore(store);

// Infer the type of `store`
export type AppStore = typeof store
// Infer the `AppDispatch` type from the store itself
export type AppDispatch = AppStore["dispatch"]
export type AppThunk<ThunkReturnType = void> = ThunkAction<
  ThunkReturnType,
  RootState,
  unknown,
  Action
>