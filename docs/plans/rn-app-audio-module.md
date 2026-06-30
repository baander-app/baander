# Custom Native Audio Module -- Technical Spec

Appendix to `docs/plans/rn-app.md` Unit 5.

---

## Architecture Overview

```
JS Layer (TypeScript)
  AudioModule.ts ────────── Unified API for React components
  NativeBaanderAudio.ts ─── TurboModule spec (Codegen)
       │
       ├── iOS/macOS (Swift via ObjC++ bridge)
       │     BaanderAudioManager.swift
       │       └── AVPlayer
       │           ├── AVPlayerItem (stream loading)
       │           ├── AVAudioSession (background + category)
       │           ├── MPNowPlayingInfoCenter (lock screen metadata)
       │           └── MPRemoteCommandCenter (lock screen controls)
       │
       ├── Android (Kotlin)
       │     BaanderAudioModule.kt
       │       └── ExoPlayer (Media3)
       │           ├── MediaSession (notification + lock screen)
       │           └── MediaBrowserServiceCompat (background service)
       │
       └── Windows (C++/WinRT)
             BaanderAudioManager.cpp
               └── MediaPlayer
                   └── SystemMediaTransportControls (taskbar + lock screen)
```

Events flow native -> JS via `RCTEventEmitter` (iOS/Android) / custom event emitter (Windows).

---

## 1. TypeScript Spec (Codegen)

File: `specs/NativeBaanderAudio.ts`

```typescript
import type { TurboModule } from 'react-native';
import { TurboModuleRegistry } from 'react-native';

export interface Spec extends TurboModule {
  // Playback control
  prepare(): void;
  play(url: string, headers: Object): void;
  pause(): void;
  resume(): void;
  seekTo(seconds: number): void;
  setVolume(level: number): void;
  stop(): void;

  // Queue management (future -- not in v1)
  // setQueue(tracks: Array<Object>): void;
  // skipToIndex(index: number): void;

  // Metadata for lock screen
  setNowPlayingMetadata(title: string, artist: string, album: string, artworkUrl: string): void;

  // Audio session category (iOS/macOS only, no-op on others)
  setCategory(category: string): void;

  // State query
  getPlaybackState(): Promise<string>;
  getCurrentTime(): Promise<number>;
  getDuration(): Promise<number>;
}

export default TurboModuleRegistry.getEnforcing<Spec>('BaanderAudio');
```

package.json codegenConfig:

```json
{
  "codegenConfig": {
    "name": "NativeBaanderAudioSpec",
    "type": "modules",
    "jsSrcsDir": "specs",
    "android": {
      "javaPackageName": "com.baander.audio"
    },
    "ios": {
      "modulesProvider": {
        "BaanderAudio": "RCTBaanderAudio"
      }
    }
  }
}
```

---

## 2. JS Wrapper Layer

File: `src/native/audio/AudioModule.ts`

Wraps the TurboModule spec with:
- `NativeEventEmitter` for native events
- Promise-based helpers
- Event subscription management

```typescript
import { NativeEventEmitter, Platform } from 'react-native';
import NativeBaanderAudio from '../../specs/NativeBaanderAudio';

type PlaybackState = 'idle' | 'loading' | 'playing' | 'paused' | 'stopped' | 'error';

interface ProgressEvent {
  position: number;   // seconds
  duration: number;   // seconds
}

const eventEmitter = new NativeEventEmitter(NativeBaanderAudio as any);

export const AudioModule = {
  prepare() {
    NativeBaanderAudio.prepare();
  },

  play(url: string, headers: Record<string, string> = {}) {
    NativeBaanderAudio.play(url, headers);
  },

  pause() {
    NativeBaanderAudio.pause();
  },

  resume() {
    NativeBaanderAudio.resume();
  },

  seekTo(seconds: number) {
    NativeBaanderAudio.seekTo(seconds);
  },

  setVolume(level: number) {
    NativeBaanderAudio.setVolume(Math.max(0, Math.min(1, level)));
  },

  stop() {
    NativeBaanderAudio.stop();
  },

  setNowPlayingMetadata(title: string, artist: string, album: string, artworkUrl: string) {
    NativeBaanderAudio.setNowPlayingMetadata(title, artist, album, artworkUrl);
  },

  async getPlaybackState(): Promise<PlaybackState> {
    return NativeBaanderAudio.getPlaybackState() as Promise<PlaybackState>;
  },

  async getCurrentTime(): Promise<number> {
    return NativeBaanderAudio.getCurrentTime();
  },

  async getDuration(): Promise<number> {
    return NativeBaanderAudio.getDuration();
  },

  // Event subscriptions
  onProgress(callback: (event: ProgressEvent) => void): () => void {
    const sub = eventEmitter.addListener('onProgress', callback);
    return () => sub.remove();
  },

  onStateChange(callback: (state: PlaybackState) => void): () => void {
    const sub = eventEmitter.addListener('onStateChange', callback);
    return () => sub.remove();
  },

  onTrackEnd(callback: () => void): () => void {
    const sub = eventEmitter.addListener('onTrackEnd', callback);
    return () => sub.remove();
  },

  onError(callback: (error: string) => void): () => void {
    const sub = eventEmitter.addListener('onError', callback);
    return () => sub.remove();
  },
};
```

---

## 3. iOS / macOS Implementation (Swift)

### File Structure

```
ios/BaanderAudio/
  RCTBaanderAudio.h          (ObjC++ header, implements generated Spec protocol)
  RCTBaanderAudio.mm          (ObjC++ bridge to Swift)
  BaanderAudioManager.swift   (Swift: AVPlayer wrapper)
```

macOS uses the same Swift files via CocoaPods target.

### RCTBaanderAudio.mm (ObjC++ bridge)

```objc
#import <React/RCTLog.h>
#import "RCTBaanderAudio.h"
// Import generated Swift header
#import "<ProjectName>-Swift.h"

@implementation RCTBaanderAudio {
  BaanderAudioManager *_manager;
}

RCT_EXPORT_MODULE(BaanderAudio)

- (instancetype)init {
  if (self = [super init]) {
    _manager = [[BaanderAudioManager alloc] initWithEventEmitter:self];
  }
  return self;
}

// Implement each method from the Spec protocol
- (void)prepare {
  [_manager prepare];
}

- (void)play:(NSString *)url headers:(NSDictionary *)headers {
  [_manager playWithURL:url headers:headers];
}

- (void)pause {
  [_manager pause];
}

- (void)resume {
  [_manager resume];
}

- (void)seekTo:(double)seconds {
  [_manager seekTo:seconds];
}

- (void)setVolume:(double)level {
  [_manager setVolume:level];
}

- (void)stop {
  [_manager stop];
}

- (void)setNowPlayingMetadata:(NSString *)title
                       artist:(NSString *)artist
                        album:(NSString *)album
                   artworkUrl:(NSString *)artworkUrl {
  [_manager setNowPlayingMetadataWithTitle:title
                                    artist:artist
                                     album:album
                                artworkUrl:artworkUrl];
}

- (void)setCategory:(NSString *)category {
  [_manager setCategory:category];
}

- (std::shared_ptr<facebook::react::TurboModule>)getTurboModule:
    (const facebook::react::ObjCTurboModule::InitParams &)params {
  return std::make_shared<facebook::react::NativeBaanderAudioSpecJSI>(params);
}

@end
```

### BaanderAudioManager.swift

```swift
import Foundation
import AVFoundation
import MediaPlayer

@objc class BaanderAudioManager: NSObject {
  private var player: AVPlayer?
  private var playerItem: AVPlayerItem?
  private var timeObserver: Any?
  private var eventEmitter: RCTEventEmitter?
  private var statusObserver: NSKeyValueObservation?
  private var endObserver: NSObjectProtocol?

  @objc init(eventEmitter: RCTEventEmitter) {
    self.eventEmitter = eventEmitter
    super.init()
  }

  @objc func prepare() {
    // Configure audio session for playback (background-capable)
    let session = AVAudioSession.sharedInstance()
    do {
      try session.setCategory(.playback, mode: .default, options: [])
      try session.setActive(true)
    } catch {
      sendEvent("onError", ["error": "Audio session config failed: \(error.localizedDescription)"])
    }
  }

  @objc func play(withUrl url: String, headers: [String: Any]) {
    guard let url = URL(string: url) else {
      sendEvent("onError", ["error": "Invalid URL"])
      return
    }

    sendEvent("onStateChange", ["state": "loading"])

    // Create AVURLAsset with custom headers (for DPoP auth)
    let asset = AVURLAsset(url: url, options: [
      "AVURLAssetHTTPHeaderFieldsKey": headers
    ])
    playerItem = AVPlayerItem(asset: asset)

    // Clean up previous player
    removeObservers()
    player = AVPlayer(playerItem: playerItem!)

    // Observe player item status (loaded / failed)
    statusObserver = playerItem?.observe(\.status, options: .new) { [weak self] item, _ in
      switch item.status {
      case .readyToPlay:
        self?.player?.play()
        self?.sendEvent("onStateChange", ["state": "playing"])
        self?.setupRemoteCommandCenter()
      case .failed:
        self?.sendEvent("onError", ["error": item.error?.localizedDescription ?? "Playback failed"])
        self?.sendEvent("onStateChange", ["state": "error"])
      @unknown default:
        break
      }
    }

    // Observe track end
    endObserver = NotificationCenter.default.addObserver(
      forName: .AVPlayerItemDidPlayToEndTime,
      object: playerItem,
      queue: .main
    ) { [weak self] _ in
      self?.sendEvent("onTrackEnd", [:])
      self?.sendEvent("onStateChange", ["state": "stopped"])
    }

    // Periodic time observer for progress (every 0.5s)
    timeObserver = player?.addPeriodicTimeObserver(
      forInterval: CMTime(seconds: 0.5, preferredTimescale: CMTimeScale(NSEC_PER_SEC)),
      queue: .main
    ) { [weak self] time in
      guard let duration = self?.playerItem?.duration else { return }
      self?.sendEvent("onProgress", [
        "position": time.seconds,
        "duration": CMTimeGetSeconds(duration).isNaN ? 0 : duration.seconds
      ])
    }
  }

  @objc func pause() {
    player?.pause()
    sendEvent("onStateChange", ["state": "paused"])
  }

  @objc func resume() {
    player?.play()
    sendEvent("onStateChange", ["state": "playing"])
  }

  @objc func seekTo(_ seconds: Double) {
    let time = CMTime(seconds: seconds, preferredTimescale: CMTimeScale(NSEC_PER_SEC))
    player?.seek(to: time)
  }

  @objc func setVolume(_ level: Float) {
    player?.volume = level
  }

  @objc func stop() {
    player?.pause()
    player?.replaceCurrentItem(with: nil)
    removeObservers()
    sendEvent("onStateChange", ["state": "stopped"])
  }

  @objc func setNowPlayingMetadata(title: String, artist: String, album: String, artworkUrl: String) {
    let info = MPNowPlayingInfoCenter.default()
    var dict: [String: Any] = [
      MPMediaItemPropertyTitle: title,
      MPMediaItemPropertyArtist: artist,
      MPMediaItemPropertyAlbumTitle: album,
    ]

    // Load artwork asynchronously
    if let url = URL(string: artworkUrl) {
      DispatchQueue.global().async {
        if let data = try? Data(contentsOf: url),
           let image = UIImage(data: data) {
          let artwork = MPMediaItemArtwork(boundsSize: image.size) { _ in image }
          dict[MPMediaItemPropertyArtwork] = artwork
          DispatchQueue.main.async {
            info.nowPlayingInfo = dict
          }
        }
      }
    }

    info.nowPlayingInfo = dict
  }

  @objc func setCategory(_ category: String) {
    let session = AVAudioSession.sharedInstance()
    let cat: AVAudioSession.Category = switch category {
      case "playback": .playback
      case "ambient": .ambient
      case "soloAmbient": .soloAmbient
      default: .playback
    }
    try? session.setCategory(cat)
  }

  // MARK: - Remote Command Center (lock screen controls)

  private func setupRemoteCommandCenter() {
    let commandCenter = MPRemoteCommandCenter.shared()

    commandCenter.playCommand.addTarget { [weak self] _ in
      self?.player?.play()
      self?.sendEvent("onStateChange", ["state": "playing"])
      return .success
    }

    commandCenter.pauseCommand.addTarget { [weak self] _ in
      self?.player?.pause()
      self?.sendEvent("onStateChange", ["state": "paused"])
      return .success
    }

    commandCenter.togglePlayPauseCommand.addTarget { [weak self] _ in
      if self?.player?.timeControlStatus == .playing {
        self?.pause()
      } else {
        self?.resume()
      }
      return .success
    }

    // Seek forward/backward (skip 15s)
    let skipInterval: Float = 15.0
    commandCenter.skipForwardCommand.preferredIntervals = [NSNumber(value: skipInterval)]
    commandCenter.skipForwardCommand.addTarget { [weak self] event in
      guard let currentTime = self?.player?.currentTime().seconds else { return .commandFailed }
      self?.seekTo(currentTime + Double(skipInterval))
      return .success
    }

    commandCenter.skipBackwardCommand.preferredIntervals = [NSNumber(value: skipInterval)]
    commandCenter.skipBackwardCommand.addTarget { [weak self] event in
      guard let currentTime = self?.player?.currentTime().seconds else { return .commandFailed }
      self?.seekTo(currentTime - Double(skipInterval))
      return .success
    }
  }

  // MARK: - Event emission

  private func sendEvent(_ name: String, _ body: [String: Any]) {
    eventEmitter?.sendEvent(withName: name, body: body)
  }

  // MARK: - Cleanup

  private func removeObservers() {
    if let observer = timeObserver {
      player?.removeTimeObserver(observer)
      timeObserver = nil
    }
    statusObserver?.invalidate()
    statusObserver = nil
    if let observer = endObserver {
      NotificationCenter.default.removeObserver(observer)
      endObserver = nil
    }
  }

  deinit {
    removeObservers()
  }
}
```

### Key iOS/macOS Notes

- `AVAudioSession.sharedInstance().setCategory(.playback)` enables background audio. Without this, audio stops when the app is backgrounded.
- `AVURLAsset` with `AVURLAssetHTTPHeaderFieldsKey` passes DPoP Authorization + DPoP headers on stream requests. This is critical for authenticated streaming.
- `MPNowPlayingInfoCenter` provides lock screen / Control Center metadata.
- `MPRemoteCommandCenter` provides lock screen transport controls (play/pause/skip).
- iOS and macOS share the same Swift code. Only the `AVAudioSession` setup differs slightly (macOS doesn't need background session activation in the same way).
- On macOS, the `MPRemoteCommandCenter` integrates with the Touch Bar and media keys.

---

## 4. Android Implementation (Kotlin)

### File Structure

```
android/app/src/main/java/com/baander/audio/
  BaanderAudioModule.kt     (TurboModule implementation)
  BaanderAudioPackage.kt    (ReactPackage registration)
  AudioService.kt           (Foreground service for background playback)
  BaanderAudioManager.kt    (ExoPlayer wrapper)
```

### AndroidManifest.xml additions

```xml
<uses-permission android:name="android.permission.FOREGROUND_SERVICE" />
<uses-permission android:name="android.permission.FOREGROUND_SERVICE_MEDIA_PLAYBACK" />
<uses-permission android:name="android.permission.WAKE_LOCK" />

<application ...>
  <service
    android:name=".audio.AudioService"
    android:exported="false"
    android:foregroundServiceType="mediaPlayback">
    <intent-filter>
      <action android:name="android.media.browse.MediaBrowserService" />
    </intent-filter>
  </service>
</application>
```

### BaanderAudioModule.kt

```kotlin
package com.baander.audio

import com.facebook.react.bridge.*
import com.facebook.react.module.annotations.ReactModule
import com.baander.audio.NativeBaanderAudioSpec  // Generated by Codegen

@ReactModule(name = BaanderAudioModule.NAME)
class BaanderAudioModule(reactContext: ReactApplicationContext) :
    NativeBaanderAudioSpec(reactContext) {

    companion object {
        const val NAME = "BaanderAudio"
    }

    private val manager = BaanderAudioManager(reactContext)

    override fun getName(): String = NAME

    override fun prepare() {
        manager.prepare()
    }

    override fun play(url: String, headers: ReadableMap) {
        val headerMap = mutableMapOf<String, String>()
        val iterator = headers.keySetIterator()
        while (iterator.hasNextKey()) {
            val key = iterator.nextKey()
            headerMap[key] = headers.getString(key) ?: ""
        }
        manager.play(url, headerMap)
    }

    override fun pause() { manager.pause() }
    override fun resume() { manager.resume() }
    override fun seekTo(seconds: Double) { manager.seekTo(seconds) }
    override fun setVolume(level: Double) { manager.setVolume(level) }
    override fun stop() { manager.stop() }

    override fun setNowPlayingMetadata(
        title: String, artist: String, album: String, artworkUrl: String
    ) {
        manager.setNowPlayingMetadata(title, artist, album, artworkUrl)
    }

    override fun setCategory(category: String) {
        // No-op on Android -- category is an iOS concept
    }

    override fun getPlaybackState(): Promise {
        val promise = Promise()
        promise.resolve(manager.getPlaybackState())
        return promise
    }

    override fun getCurrentTime(): Promise {
        val promise = Promise()
        promise.resolve(manager.getCurrentTime())
        return promise
    }

    override fun getDuration(): Promise {
        val promise = Promise()
        promise.resolve(manager.getDuration())
        return promise
    }
}
```

### BaanderAudioManager.kt (ExoPlayer wrapper)

```kotlin
package com.baander.audio

import android.content.Context
import android.net.Uri
import android.os.Looper
import androidx.media3.common.*
import androidx.media3.exoplayer.ExoPlayer
import androidx.media3.common.Player
import com.facebook.react.bridge.ReactApplicationContext
import com.facebook.react.modules.core.DeviceEventManagerModule

class BaanderAudioManager(private val context: ReactApplicationContext) {

    private var player: ExoPlayer? = null
    private var mediaSession: MediaSession? = null

    fun prepare() {
        if (player != null) return

        player = ExoPlayer.Builder(context).build().apply {
            addListener(playerListener)
        }

        // MediaSession for notification + lock screen
        mediaSession = MediaSession.Builder(context, player!!).build()
    }

    fun play(url: String, headers: Map<String, String>) {
        sendEvent("onStateChange", "loading")

        val mediaItem = MediaItem.Builder()
            .setUri(Uri.parse(url))
            .build()

        // Create DataSource with custom headers for DPoP auth
        val dataSourceFactory = object : DataSource.Factory {
            override fun createDataSource(): DataSource {
                return DefaultHttpDataSource.Factory()
                    .setDefaultRequestProperties(headers)
                    .createDataSource()
            }
        }

        player?.let { p ->
            val mediaSource = ProgressiveMediaSource.Factory(dataSourceFactory)
                .createMediaSource(mediaItem)
            p.setMediaSource(mediaSource)
            p.prepare()
            p.playWhenReady = true
        }
    }

    fun pause() {
        player?.pause()
        sendEvent("onStateChange", "paused")
    }

    fun resume() {
        player?.play()
        sendEvent("onStateChange", "playing")
    }

    fun seekTo(seconds: Double) {
        player?.seekTo((seconds * 1000).toLong())
    }

    fun setVolume(level: Float) {
        player?.volume = level
    }

    fun stop() {
        player?.stop()
        player?.clearMediaItems()
        sendEvent("onStateChange", "stopped")
    }

    fun setNowPlayingMetadata(title: String, artist: String, album: String, artworkUrl: String) {
        val metadata = MediaMetadata.Builder()
            .setTitle(title)
            .setArtist(artist)
            .setAlbumTitle(album)
            .setArtworkUri(Uri.parse(artworkUrl))
            .build()

        // Update current media item with metadata
        player?.currentMediaItem?.mediaMetadata?.let {
            val updatedItem = it.buildUpon()
                .setMediaMetadata(metadata)
                .build()
            // MediaSession picks this up for notification
        }
    }

    fun getPlaybackState(): String {
        return when (player?.playbackState) {
            Player.STATE_IDLE -> "idle"
            Player.STATE_BUFFERING -> "loading"
            Player.STATE_READY -> if (player?.isPlaying == true) "playing" else "paused"
            Player.STATE_ENDED -> "stopped"
            else -> "idle"
        }
    }

    fun getCurrentTime(): Double {
        return (player?.currentPosition?.toDouble() ?: 0.0) / 1000.0
    }

    fun getDuration(): Double {
        return (player?.duration?.toDouble() ?: 0.0) / 1000.0
    }

    private val playerListener = object : Player.Listener {
        override fun onIsPlayingChanged(isPlaying: Boolean) {
            if (isPlaying) {
                sendEvent("onStateChange", "playing")
            }
        }

        override fun onPlaybackStateChanged(state: Int) {
            when (state) {
                Player.STATE_ENDED -> {
                    sendEvent("onTrackEnd", "")
                    sendEvent("onStateChange", "stopped")
                }
                Player.STATE_BUFFERING -> {
                    sendEvent("onStateChange", "loading")
                }
            }
        }

        override fun onPlayerError(error: PlaybackException) {
            sendEvent("onError", error.message ?: "Unknown playback error")
            sendEvent("onStateChange", "error")
        }
    }

    // Periodic progress updates via coroutine or handler
    private val progressHandler = android.os.Handler(Looper.getMainLooper())
    private val progressRunnable = object : Runnable {
        override fun run() {
            player?.let { p ->
                if (p.isPlaying) {
                    sendEvent("onProgress", mapOf(
                        "position" to p.currentPosition / 1000.0,
                        "duration" to (if (p.duration == androidx.media3.common.C.TIME_UNSET) 0.0 else p.duration / 1000.0)
                    ))
                }
            }
            progressHandler.postDelayed(this, 500)
        }
    }

    fun startProgressUpdates() {
        progressHandler.post(progressRunnable)
    }

    fun stopProgressUpdates() {
        progressHandler.removeCallbacks(progressRunnable)
    }

    private fun sendEvent(name: String, body: Any) {
        context.getJSModule(DeviceEventManagerModule.RCTDeviceEventEmitter::class.java)
            ?.emit(name, body)
    }

    fun release() {
        stopProgressUpdates()
        mediaSession?.release()
        player?.release()
        player = null
        mediaSession = null
    }
}
```

### AudioService.kt (Foreground Service for Background Playback)

```kotlin
package com.baander.audio

import android.app.*
import android.content.Intent
import android.os.Build
import android.os.IBinder
import androidx.core.app.NotificationCompat
import androidx.media3.common.Player
import androidx.media3.session.MediaSession

class AudioService : Service() {

    private var mediaSession: MediaSession? = null

    override fun onCreate() {
        super.onCreate()
        createNotificationChannel()

        // MediaSession is managed by BaanderAudioManager
        // This service keeps the app alive in background
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        val notification = createNotification()
        startForeground(1, notification)
        return START_STICKY
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onDestroy() {
        mediaSession?.release()
        super.onDestroy()
    }

    private fun createNotificationChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val channel = NotificationChannel(
                "baander_playback",
                "Playback",
                NotificationManager.IMPORTANCE_LOW
            )
            val manager = getSystemService(NotificationManager::class.java)
            manager.createNotificationChannel(channel)
        }
    }

    private fun createNotification(): Notification {
        return NotificationCompat.Builder(this, "baander_playback")
            .setContentTitle("Bander")
            .setContentText("Playing music")
            .setSmallIcon(android.R.drawable.ic_media_play)
            .setPriority(NotificationCompat.PRIORITY_LOW)
            .build()
    }
}
```

### BaanderAudioPackage.kt

```kotlin
package com.baander.audio

import com.facebook.react.ReactPackage
import com.facebook.react.bridge.NativeModule
import com.facebook.react.bridge.ReactApplicationContext
import com.facebook.react.uimanager.ViewManager

class BaanderAudioPackage : ReactPackage {
    override fun createNativeModules(reactContext: ReactApplicationContext): List<NativeModule> {
        return listOf(BaanderAudioModule(reactContext))
    }

    override fun createViewManagers(reactContext: ReactApplicationContext): List<ViewManager<*, *>> {
        return emptyList()
    }
}
```

Register in `MainApplication.kt`:
```kotlin
override fun getPackages(): List<ReactPackage> =
    PackageList(this).packages.apply {
        add(BaanderAudioPackage())
    }
```

### Key Android Notes

- ExoPlayer (AndroidX Media3) is the standard audio/video player. Bundled with the app, no system dependency.
- `MediaSession` provides notification controls and lock screen integration. Media3 handles this automatically when connected to a `MediaSession`.
- `FOREGROUND_SERVICE_MEDIA_PLAYBACK` permission + foreground service keeps playback alive when the app is backgrounded. Android 14+ requires this.
- Custom `DataSource.Factory` injects DPoP auth headers into stream requests.
- Progress updates via `Handler.postDelayed` every 500ms (matches iOS).

---

## 5. Windows Implementation (C++/WinRT)

### File Structure

```
windows/BaanderAudio/
  BaanderAudioManager.h     (C++/WinRT header)
  BaanderAudioManager.cpp   (C++/WinRT implementation)
  BaanderAudioModule.h      (RN TurboModule wrapper)
  BaanderAudioModule.cpp
```

### BaanderAudioManager.h

```cpp
#pragma once

#include <winrt/Windows.Foundation.h>
#include <winrt/Windows.Media.Playback.h>
#include <winrt/Windows.Media.Control.h>
#include <winrt/Windows.Storage.Streams.h>
#include <string>
#include <functional>

namespace BaanderAudio {

struct AudioCallbacks {
    std::function<void(double position, double duration)> onProgress;
    std::function<void(const std::string& state)> onStateChange;
    std::function<void()> onTrackEnd;
    std::function<void(const std::string& error)> onError;
};

class BaanderAudioManager {
public:
    BaanderAudioManager();
    ~BaanderAudioManager();

    void Prepare();
    void Play(const std::string& url, const std::map<std::string, std::string>& headers);
    void Pause();
    void Resume();
    void SeekTo(double seconds);
    void SetVolume(double level);
    void Stop();

    void SetNowPlayingMetadata(
        const std::string& title,
        const std::string& artist,
        const std::string& album,
        const std::string& artworkUrl
    );

    void SetCallbacks(AudioCallbacks callbacks);

    std::string GetPlaybackState() const;
    double GetCurrentTime() const;
    double GetDuration() const;

private:
    winrt::Windows::Media::Playback::MediaPlayer m_player{ nullptr };
    winrt::Windows::Media::Control::SystemMediaTransportControls m_smtc{ nullptr };
    AudioCallbacks m_callbacks;
    std::string m_state{ "idle" };
    bool m_progressTimerActive{ false };
    winrt::Windows::Foundation::IAsyncAction m_progressTimer{ nullptr };

    void SetupSMTC();
    void StartProgressTimer();
    void StopProgressTimer();
    void EmitState(const std::string& state);
};

} // namespace BaanderAudio
```

### BaanderAudioManager.cpp (key methods)

```cpp
#include "BaanderAudioManager.h"
#include <winrt/Windows.Foundation.Collections.h>
#include <winrt/Windows.Web.Http.h>

using namespace winrt;
using namespace Windows::Media::Playback;
using namespace Windows::Media::Control;
using namespace Windows::Foundation;

namespace BaanderAudio {

BaanderAudioManager::BaanderAudioManager() {
    m_player = MediaPlayer();
    m_player.AutoPlay(false);

    // Get SMTC from player
    m_smtc = m_player.SystemMediaTransportControls();
    SetupSMTC();

    // Listen for media ended
    m_player.MediaEnded([this](auto&&, auto&&) {
        EmitState("stopped");
        if (m_callbacks.onTrackEnd) m_callbacks.onTrackEnd();
    });

    m_player.MediaFailed([this](auto&&, auto&& args) {
        EmitState("error");
        if (m_callbacks.onError) {
            m_callbacks.onError(winrt::to_string(args.ErrorMessage()));
        }
    });

    m_player.CurrentStateChanged([this](auto&&, auto&&) {
        switch (m_player.PlaybackSession().PlaybackState()) {
            case MediaPlaybackState::Playing:
                EmitState("playing");
                StartProgressTimer();
                break;
            case MediaPlaybackState::Paused:
                EmitState("paused");
                StopProgressTimer();
                break;
            case MediaPlaybackState::Buffering:
                EmitState("loading");
                break;
            default:
                break;
        }
    });
}

void BaanderAudioManager::Play(const std::string& url, const std::map<std::string, std::string>& headers) {
    EmitState("loading");

    auto uri = Uri(winrt::to_hstring(url));

    // Create MediaSource with custom headers
    // Windows: use HttpRandomAccessStream for header injection
    // For simplicity, direct source. DPoP headers require custom HTTP client.
    auto source = Windows::Media::Core::MediaSource::CreateFromUri(uri);
    auto item = MediaPlaybackItem(source);

    m_player.Source(item);
    m_player.Play();
}

void BaanderAudioManager::Pause() {
    m_player.Pause();
}

void BaanderAudioManager::Resume() {
    m_player.Play();
}

void BaanderAudioManager::SeekTo(double seconds) {
    auto span = TimeSpan{ static_cast<int64_t>(seconds * 10000000) }; // 100ns units
    m_player.PlaybackSession().Position(span);
}

void BaanderAudioManager::SetVolume(double level) {
    m_player.Volume(level);
}

void BaanderAudioManager::Stop() {
    m_player.Pause();
    m_player.Source(nullptr);
    EmitState("stopped");
}

void BaanderAudioManager::SetupSMTC() {
    m_smtc.IsEnabled(true);
    m_smtc.IsPlayEnabled(true);
    m_smtc.IsPauseEnabled(true);
    m_smtc.IsNextEnabled(false);  // Queue management comes later
    m_smtc.IsPreviousEnabled(false);
    m_smtc.PlaybackStatus(MediaPlaybackStatus::Closed);

    m_smtc.ButtonPressed([this](auto&&, auto&& args) {
        switch (args.Button()) {
            case SystemMediaTransportControlsButton::Play:
                m_player.Play();
                break;
            case SystemMediaTransportControlsButton::Pause:
                m_player.Pause();
                break;
            default:
                break;
        }
    });
}

void BaanderAudioManager::SetNowPlayingMetadata(
    const std::string& title, const std::string& artist,
    const std::string& album, const std::string& artworkUrl) {

    auto updater = m_smtc.DisplayUpdater();
    updater.Type(Windows::Media::MediaPlaybackType::Music);
    auto props = updater.MusicProperties();
    props.Title(winrt::to_hstring(title));
    props.Artist(winrt::to_hstring(artist));
    props.AlbumTitle(winrt::to_hstring(album));

    // Thumbnail from URL (async)
    if (!artworkUrl.empty()) {
        try {
            auto uri = Uri(winrt::to_hstring(artworkUrl));
            auto stream = Windows::Storage::Streams::RandomAccessStreamReference::CreateFromUri(uri);
            updater.Thumbnail(stream);
        } catch (...) {}
    }

    updater.Update();
}

void BaanderAudioManager::StartProgressTimer() {
    if (m_progressTimerActive) return;
    m_progressTimerActive = true;

    // Use ThreadPool timer for periodic progress
    // Implementation uses Windows::System::Threading::ThreadPoolTimer
    // Fires every 500ms, emits onProgress
}

void BaanderAudioManager::StopProgressTimer() {
    m_progressTimerActive = false;
    if (m_progressTimer) {
        m_progressTimer.Cancel();
        m_progressTimer = nullptr;
    }
}

void BaanderAudioManager::EmitState(const std::string& state) {
    m_state = state;
    if (m_callbacks.onStateChange) m_callbacks.onStateChange(state);
}

std::string BaanderAudioManager::GetPlaybackState() const { return m_state; }
double BaanderAudioManager::GetCurrentTime() const {
    return m_player.PlaybackSession().Position().count() / 10000000.0;
}
double BaanderAudioManager::GetDuration() const {
    auto dur = m_player.PlaybackSession().NaturalDuration();
    return dur.count() / 10000000.0;
}

} // namespace BaanderAudio
```

### Key Windows Notes

- `MediaPlayer` from `Windows.Media.Playback` is the native audio player.
- `SystemMediaTransportControls` (SMTC) provides taskbar media controls and lock screen integration.
- Custom HTTP headers for DPoP require `HttpRandomAccessStream` or a custom `IInputStream` -- Windows `MediaSource` doesn't natively support custom headers on URIs. This is a known gap; workaround is to download stream chunks via `Windows::Web::Http::HttpClient` with headers and feed to `MediaSource::CreateFromStream`.
- Progress timer via `ThreadPoolTimer` every 500ms.
- C++/WinRT is the recommended projection (not C++/CX).

---

## 6. DPoP Auth Headers on Stream URLs

The Baander backend requires DPoP auth on `/api/stream/track?id=...`. The custom headers must include:
- `Authorization: DPoP <accessToken>`
- `DPoP: <proof-jwt>`

### Platform handling:

| Platform | Header injection method |
|----------|----------------------|
| iOS/macOS | `AVURLAsset` with `AVURLAssetHTTPHeaderFieldsKey` dictionary |
| Android | ExoPlayer `DefaultHttpDataSource.Factory().setDefaultRequestProperties(headers)` |
| Windows | Custom `HttpClient` + `IInputStream` adapter (most complex) |

The JS layer provides headers to `AudioModule.play(url, headers)`. The player store constructs these from the auth store:

```typescript
// In player-store, when calling play:
const { accessToken } = useAuthStore.getState();
const keyPair = getDpopKeyPair();
const proof = await createDpopProof(keyPair, 'GET', buildHtu(streamUrl));
const headers = {
  'Authorization': `DPoP ${accessToken}`,
  'DPoP': proof,
};
AudioModule.play(streamUrl, headers);
```

This reuses the shared `ui/shared/crypto/dpop-proof.ts` module.

---

## 7. Testing Strategy

### JS Layer Tests (Vitest)
- Mock the native module (TurboModuleRegistry returns mock)
- Test AudioModule wrapper event subscriptions
- Test player-store queue logic against mocked AudioModule

### iOS/macOS Tests (XCTest)
- AVPlayer instantiation and playback of a test stream
- AVAudioSession category set correctly
- MPNowPlayingInfoCenter metadata updates
- MPRemoteCommandCenter responds to commands
- Background audio continues after app backgrounding

### Android Tests (Robolectric / Instrumented)
- ExoPlayer instantiation and playback
- MediaSession created and active
- Foreground service starts/stops correctly
- Custom headers passed to DataSource

### Windows Tests (TAEF / Google Test)
- MediaPlayer instantiation
- SMTC enabled and responsive
- Progress timer fires at correct intervals
