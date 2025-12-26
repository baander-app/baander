import { Category, Component, Palette, Variant } from '@react-buddy/ide-toolbox';
import { Brand } from '@/app/ui/brand/Brand.tsx';
import { AlertLoadingError } from '@/app/ui/alerts/alert-loading-error.tsx';
import { BaanderLogo } from '@/app/ui/branding/baander-logo/baander-logo.tsx';
import { LyricsIcon } from '@/app/ui/icons/libary.tsx';
import { LyricsViewer } from '@/app/ui/lyrics-viewer/lyrics-viewer.tsx';
import { DotButton } from '@/app/ui/carousel/components/dot-button.tsx';
import { NextButton, PrevButton } from '@/app/ui/carousel/components/arrow-buttons.tsx';
import { BeachBall } from '@/app/ui/feedback/loading/beach-ball.tsx';
import { Toast } from '@/app/ui/toast/toast.tsx';
import Alert from '@/app/ui/alerts/alert.tsx';
import {
  PlayerKaraokeIcon,
  PlayerNextIcon,
  PlayerPauseIcon,
  PlayerPlayIcon,
  PlayerPreviousIcon,
  PlayerVolumeLevelFullIcon,
  PlayerVolumeLevelHighIcon,
  PlayerVolumeMediumIcon,
  PlayerVolumeMutedIcon,
  PlayerWaveFormIcon,
  PlayerWaveFormSlashIcon,
} from '@/app/ui/icons/player.tsx';
import { LyricsSettings } from '@/app/ui/lyrics-viewer/components/lyrics-settings/lyrics-settings.tsx';
import { SideNav } from '@/app/ui/side-nav/side-nav.tsx';
import { UserTable } from '@/app/ui/users/user-table/user-table.tsx';
import { UserButton } from '@/app/ui/user-button/user-button.tsx';
import { CloseButton } from '@/app/ui/buttons/close-button.tsx';

export const PaletteTree = () => (
  <Palette>
    <Category name="ui">
      <Component name="Brand">
        <Variant>
          <Brand/>
        </Variant>
      </Component>
      <Component name="AlertLoadingError">
        <Variant>
          <AlertLoadingError/>
        </Variant>
      </Component>
      <Component name="BaanderLogo">
        <Variant>
          <BaanderLogo/>
        </Variant>
      </Component>
      <Component name="LyricsIcon">
        <Variant>
          <LyricsIcon/>
        </Variant>
      </Component>
      <Component name="LyricsViewer">
        <Variant>
          <LyricsViewer/>
        </Variant>
      </Component>
      <Component name="DotButton">
        <Variant>
          <DotButton/>
        </Variant>
      </Component>
      <Component name="PrevButton">
        <Variant>
          <PrevButton/>
        </Variant>
      </Component>
      <Component name="NextButton">
        <Variant>
          <NextButton/>
        </Variant>
      </Component>
      <Component name="BeachBall">
        <Variant>
          <BeachBall/>
        </Variant>
      </Component>
      <Component name="Toast">
        <Variant>
          <Toast/>
        </Variant>
      </Component>
      <Component name="Alert">
        <Variant>
          <Alert/>
        </Variant>
      </Component>
      <Component name="Toast">
        <Variant>
          <Toast/>
        </Variant>
      </Component>
      <Component name="PlayerPlayIcon">
        <Variant>
          <PlayerPlayIcon/>
        </Variant>
      </Component>
      <Component name="PlayerPauseIcon">
        <Variant>
          <PlayerPauseIcon/>
        </Variant>
      </Component>
      <Component name="PlayerNextIcon">
        <Variant>
          <PlayerNextIcon/>
        </Variant>
      </Component>
      <Component name="PlayerPreviousIcon">
        <Variant>
          <PlayerPreviousIcon/>
        </Variant>
      </Component>
      <Component name="PlayerKaraokeIcon">
        <Variant>
          <PlayerKaraokeIcon/>
        </Variant>
      </Component>
      <Component name="PlayerWaveFormIcon">
        <Variant>
          <PlayerWaveFormIcon/>
        </Variant>
      </Component>
      <Component name="PlayerWaveFormSlashIcon">
        <Variant>
          <PlayerWaveFormSlashIcon/>
        </Variant>
      </Component>
      <Component name="PlayerVolumeMutedIcon">
        <Variant>
          <PlayerVolumeMutedIcon/>
        </Variant>
      </Component>
      <Component name="PlayerVolumeMediumIcon">
        <Variant>
          <PlayerVolumeMediumIcon/>
        </Variant>
      </Component>
      <Component name="PlayerVolumeLevelHighIcon">
        <Variant>
          <PlayerVolumeLevelHighIcon/>
        </Variant>
      </Component>
      <Component name="PlayerVolumeLevelFullIcon">
        <Variant>
          <PlayerVolumeLevelFullIcon/>
        </Variant>
      </Component>
      <Component name="LyricsSettings">
        <Variant>
          <LyricsSettings/>
        </Variant>
      </Component>
      <Component name="SideNav">
        <Variant>
          <SideNav/>
        </Variant>
      </Component>
      <Component name="UserTable">
        <Variant>
          <UserTable/>
        </Variant>
      </Component>
      <Component name="UserButton">
        <Variant>
          <UserButton/>
        </Variant>
      </Component>
      <Component name="CloseButton">
        <Variant>
          <CloseButton/>
        </Variant>
      </Component>
    </Category>
  </Palette>
);
