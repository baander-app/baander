import { Action, combineSlices, configureStore, ThunkAction } from '@reduxjs/toolkit';
import { persistReducer, persistStore } from 'redux-persist';
import storage from 'redux-persist/lib/storage';
import { equalizerSlice } from '@/app/store/audio/equalizer.ts';
import { musicPlayerSlice } from '@/app/store/music/music-player-slice.ts';
import { notificationsSlice } from '@/app/store/notifications/notifications-slice.ts';
import { uiSlice } from '@/app/store/users/ui-slice.ts';
import { eventBridgeMiddleware } from '@/app/store/middleware/event-bridge.middleware.ts';

const rootReducer = combineSlices(
  equalizerSlice,
  musicPlayerSlice,
  notificationsSlice,
  uiSlice,
);
export type RootState = ReturnType<typeof rootReducer>

const persistConfig = {
  key: 'root',
  storage,
};
const persistedReducer = persistReducer(persistConfig, rootReducer);// as unknown as typeof rootReducer;

export const makeStore = () => {
  return configureStore({
    devTools: true,
    reducer: persistedReducer,
    middleware: (getDefaultMiddleware) =>
      getDefaultMiddleware({
        serializableCheck: false,
      }).concat(eventBridgeMiddleware)
  });
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