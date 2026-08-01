/**
 * @module immersive/ImmersiveRenderer
 * @description WebGL-based immersive video rendering for 360° equirectangular,
 * cubemap, and flat projection types. Supports WebXR VR/AR sessions.
 *
 * The renderer creates a sphere (or plane) geometry, maps video as a texture,
 * and renders through a perspective camera controlled by device orientation,
 * mouse/touch drag, or VR headset tracking.
 *
 * Projection types:
 *   - equirectangular: Standard 360° video mapped onto a sphere
 *   - cubemap: 6-face cube map texture
 *   - half-equirectangular: 180° hemispherical projection
 *   - flat: Standard 2D video (no spatial features)
 *
 * WebXR integration:
 *   - "Enter VR" creates an XR session with the immersive-vr mode
 *   - "Enter AR" creates an XR session with the immersive-ar mode
 *   - Uses Three.js XRManager for camera and controller tracking
 *
 * Dependencies:
 *   - Three.js is the only external dependency (peer dependency)
 *   - Falls back to a simpler canvas-based renderer if Three.js unavailable
 */

import type { ProjectionType, SpatialState } from '../types';

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

export interface ImmersiveConfig {
  /** Initial projection type. */
  projection: ProjectionType;
  /** Sphere radius for equirectangular. */
  sphereRadius: number;
  /** Sphere segments (higher = smoother). */
  sphereSegments: number;
  /** Default field of view in degrees. */
  defaultFov: number;
  /** Enable stereo rendering for VR. */
  stereo: boolean;
}

const DEFAULT_IMMERSIVE_CONFIG: ImmersiveConfig = {
  projection: 'equirectangular',
  sphereRadius: 50,
  sphereSegments: 64,
  defaultFov: 75,
  stereo: false,
};

// ---------------------------------------------------------------------------
// Three.js Type Stubs (for when Three.js is not imported)
// ---------------------------------------------------------------------------

type ThreeScene = import('three').Scene;
type ThreeCamera = import('three').Camera;
type ThreeWebGLRenderer = import('three').WebGLRenderer;
type ThreeMesh = import('three').Mesh;
type ThreeTexture = import('three').Texture;

// ---------------------------------------------------------------------------
// ImmersiveRenderer
// ---------------------------------------------------------------------------

export interface ImmersiveEvents {
  onSpatialStateChange: (state: SpatialState) => void;
  onXRSessionStart: (mode: 'vr' | 'ar') => void;
  onXRSessionEnd: () => void;
  onError: (error: Error) => void;
}

/**
 * ImmersiveRenderer — manages WebGL rendering of spatial video.
 *
 * This class lazily imports Three.js to keep the bundle lightweight for
 * flat (non-immersive) playback.
 *
 * Usage:
 * ```ts
 * const renderer = new ImmersiveRenderer(container, videoElement, config, events);
 * await renderer.init();
 * renderer.setProjection('equirectangular');
 * renderer.startRenderLoop();
 *
 * // Enter VR
 * await renderer.enterXR('vr');
 *
 * // Clean up
 * renderer.destroy();
 * ```
 */
export class ImmersiveRenderer {
  private scene: ThreeScene | null = null;
  private camera: ThreeCamera | null = null;
  private renderer: ThreeWebGLRenderer | null = null;
  private sphere: ThreeMesh | null = null;
  private videoTexture: ThreeTexture | null = null;
  private xrSession: any = null;
  private animationFrameId: number | null = null;

  private spatialState: SpatialState = {
    projection: 'flat',
    yaw: 0,
    pitch: 0,
    fov: 75 * (Math.PI / 180),
    xrActive: false,
  };

  private isDragging = false;
  private lastMouseX = 0;
  private lastMouseY = 0;

  /** AbortController for all DOM event listeners — enables clean teardown. */
  private listenerAbortController: AbortController | null = null;

  /** Cached Three.js module reference — set once in init(), used synchronously afterwards. */
  private threeModule: typeof import('three') | null = null;

  constructor(
    private readonly container: HTMLElement,
    private readonly videoElement: HTMLVideoElement,
    private readonly config: ImmersiveConfig,
    private readonly events: ImmersiveEvents,
  ) {
    this.spatialState.projection = config.projection;
    this.spatialState.fov = config.defaultFov * (Math.PI / 180);
  }

  /** Initialize the renderer. Must be called before any rendering. */
  async init(): Promise<void> {
    try {
      const THREE = await import('three');
      this.setupThreeScene(THREE);
    } catch {
      throw new Error(
        'Three.js is required for immersive rendering. ' +
        'Install it with: yarn add three',
      );
    }
  }

  /** Set the projection type. Triggers geometry rebuild. */
  setProjection(projection: ProjectionType): void {
    this.spatialState.projection = projection;
    this.events.onSpatialStateChange(this.spatialState);

    if (projection === 'flat') {
      // No special rendering needed — use the video element directly
      this.stopRenderLoop();
      return;
    }

    this.rebuildGeometry();
    this.startRenderLoop();
  }

  /** Start the render loop. */
  startRenderLoop(): void {
    if (this.animationFrameId !== null) return;

    const loop = () => {
      this.animationFrameId = requestAnimationFrame(loop);
      this.render();
    };

    this.animationFrameId = requestAnimationFrame(loop);
  }

  /** Stop the render loop. */
  stopRenderLoop(): void {
    if (this.animationFrameId !== null) {
      cancelAnimationFrame(this.animationFrameId);
      this.animationFrameId = null;
    }
  }

  /** Enter WebXR session. */
  async enterXR(mode: 'vr' | 'ar'): Promise<void> {
    if (!this.renderer || !(navigator as any).xr) {
      throw new Error('WebXR not available');
    }

    const sessionMode = mode === 'vr' ? 'immersive-vr' : 'immersive-ar';
    const supported = await (navigator as any).xr?.isSessionSupported(sessionMode);
    if (!supported) {
      throw new Error(`WebXR ${mode.toUpperCase()} not supported on this device`);
    }

    this.xrSession = await (navigator as any).xr.requestSession(sessionMode, {
      optionalFeatures: ['local-floor', 'bounded-floor'],
    });

    this.spatialState.xrActive = true;
    this.events.onXRSessionStart(mode);
    this.events.onSpatialStateChange(this.spatialState);

    // Set up XR rendering
    this.xrSession.addEventListener('end', () => {
      this.xrSession = null;
      this.spatialState.xrActive = false;
      this.events.onXRSessionEnd();
      this.events.onSpatialStateChange(this.spatialState);
    });

    if ((this.renderer as any).xr) {
      (this.renderer as any).xr.enabled = true;
      (this.renderer as any).xr.setSession(this.xrSession);
    }
  }

  /** Exit WebXR session. */
  exitXR(): void {
    this.xrSession?.end();
    this.xrSession = null;
  }

  /** Get current spatial state. */
  getSpatialState(): Readonly<SpatialState> {
    return this.spatialState;
  }

  /** Manually set camera orientation (for programmatic control). */
  setOrientation(yaw: number, pitch: number): void {
    this.spatialState.yaw = yaw;
    this.spatialState.pitch = pitch;
    this.events.onSpatialStateChange(this.spatialState);
  }

  /** Handle window resize. */
  resize(): void {
    if (!this.renderer || !this.camera) return;

    const width = this.container.clientWidth;
    const height = this.container.clientHeight;

    this.renderer.setSize(width, height);

    // Update camera aspect ratio
    if ('aspect' in this.camera) {
      (this.camera as any).aspect = width / height;
      (this.camera as any).updateProjectionMatrix?.();
    }
  }

  /** Destroy all resources and remove event listeners. */
  destroy(): void {
    this.stopRenderLoop();
    this.exitXR();
    this.listenerAbortController?.abort();
    this.listenerAbortController = null;
    this.renderer?.dispose();
    this.videoTexture?.dispose();
    this.threeModule = null;
    this.renderer = null;
    this.scene = null;
    this.camera = null;
    this.sphere = null;
    this.videoTexture = null;
  }

  // -----------------------------------------------------------------------
  // Private: Three.js Setup
  // -----------------------------------------------------------------------

  private setupThreeScene(THREE: typeof import('three')): void {
    // Cache the module for synchronous use in rebuildGeometry()
    this.threeModule = THREE;

    // Scene
    this.scene = new THREE.Scene();

    // Camera
    this.camera = new THREE.PerspectiveCamera(
      this.config.defaultFov,
      this.container.clientWidth / this.container.clientHeight,
      0.1,
      1000,
    );
    this.camera.position.set(0, 0, 0);

    // Renderer
    this.renderer = new THREE.WebGLRenderer({
      antialias: true,
      alpha: false,
    });
    this.renderer.setSize(this.container.clientWidth, this.container.clientHeight);
    this.renderer.setPixelRatio(window.devicePixelRatio);
    this.container.appendChild(this.renderer.domElement);

    // Video texture
    this.videoTexture = new THREE.VideoTexture(this.videoElement);
    this.videoTexture.minFilter = THREE.LinearFilter;
    this.videoTexture.magFilter = THREE.LinearFilter;
    this.videoTexture.colorSpace = THREE.SRGBColorSpace;

    // Build geometry
    this.rebuildGeometry();

    // Mouse/touch controls
    this.setupControls();
  }

  private rebuildGeometry(): void {
    if (!this.scene || !this.videoTexture || !this.threeModule) return;

    const THREE = this.threeModule;

    // Remove old sphere
    if (this.sphere) {
      this.scene.remove(this.sphere);
    }

    let geometry: InstanceType<typeof THREE.BufferGeometry>;

    switch (this.spatialState.projection) {
      case 'equirectangular':
        geometry = new THREE.SphereGeometry(
          this.config.sphereRadius,
          this.config.sphereSegments,
          this.config.sphereSegments,
        );
        // Invert the sphere so the texture is on the inside
        geometry.scale(-1, 1, 1);
        break;

      case 'half-equirectangular':
        geometry = new THREE.SphereGeometry(
          this.config.sphereRadius,
          this.config.sphereSegments / 2,
          this.config.sphereSegments,
          0,
          Math.PI, // Only front hemisphere
        );
        geometry.scale(-1, 1, 1);
        break;

      case 'cubemap':
        geometry = new THREE.BoxGeometry(
          this.config.sphereRadius * 2,
          this.config.sphereRadius * 2,
          this.config.sphereRadius * 2,
        );
        geometry.scale(-1, 1, 1);
        break;

      case 'flat':
      default:
        return; // No geometry for flat mode
    }

    const material = new THREE.MeshBasicMaterial({
      map: this.videoTexture,
      side: THREE.FrontSide,
    });

    this.sphere = new THREE.Mesh(geometry as any, material);
    this.scene.add(this.sphere);
  }

  private render(): void {
    if (!this.renderer || !this.scene || !this.camera) return;

    // Update camera rotation from spatial state
    if ('rotation' in this.camera) {
      this.camera.rotation.order = 'YXZ';
      this.camera.rotation.y = this.spatialState.yaw;
      this.camera.rotation.x = this.spatialState.pitch;
    }

    // Update camera FOV when it changes (e.g. via scroll-to-zoom)
    if ('fov' in this.camera) {
      const fovDegrees = this.spatialState.fov * (180 / Math.PI);
      if (Math.abs((this.camera as any).fov - fovDegrees) > 0.01) {
        (this.camera as any).fov = fovDegrees;
        (this.camera as any).updateProjectionMatrix();
      }
    }

    this.renderer.render(this.scene, this.camera);
  }

  private setupControls(): void {
    // Abort previous listeners if re-initialising
    this.listenerAbortController?.abort();
    this.listenerAbortController = new AbortController();
    const signal = this.listenerAbortController.signal;
    const el = this.container;

    // Mouse drag
    el.addEventListener('mousedown', (e) => {
      this.isDragging = true;
      this.lastMouseX = e.clientX;
      this.lastMouseY = e.clientY;
    }, { signal });

    el.addEventListener('mousemove', (e) => {
      if (!this.isDragging) return;
      const deltaX = e.clientX - this.lastMouseX;
      const deltaY = e.clientY - this.lastMouseY;
      this.lastMouseX = e.clientX;
      this.lastMouseY = e.clientY;

      this.spatialState.yaw -= deltaX * 0.005;
      this.spatialState.pitch -= deltaY * 0.005;
      this.spatialState.pitch = Math.max(-Math.PI / 2, Math.min(Math.PI / 2, this.spatialState.pitch));

      this.events.onSpatialStateChange(this.spatialState);
    }, { signal });

    el.addEventListener('mouseup', () => { this.isDragging = false; }, { signal });
    el.addEventListener('mouseleave', () => { this.isDragging = false; }, { signal });

    // Touch drag
    el.addEventListener('touchstart', (e) => {
      if (e.touches.length === 1) {
        this.isDragging = true;
        this.lastMouseX = e.touches[0]!.clientX;
        this.lastMouseY = e.touches[0]!.clientY;
      }
    }, { signal });

    el.addEventListener('touchmove', (e) => {
      if (!this.isDragging || e.touches.length !== 1) return;
      const deltaX = e.touches[0]!.clientX - this.lastMouseX;
      const deltaY = e.touches[0]!.clientY - this.lastMouseY;
      this.lastMouseX = e.touches[0]!.clientX;
      this.lastMouseY = e.touches[0]!.clientY;

      this.spatialState.yaw -= deltaX * 0.005;
      this.spatialState.pitch -= deltaY * 0.005;
      this.spatialState.pitch = Math.max(-Math.PI / 2, Math.min(Math.PI / 2, this.spatialState.pitch));

      this.events.onSpatialStateChange(this.spatialState);
    }, { signal });

    el.addEventListener('touchend', () => { this.isDragging = false; }, { signal });

    // Wheel zoom (FOV)
    el.addEventListener('wheel', (e) => {
      e.preventDefault();
      this.spatialState.fov += e.deltaY * 0.005;
      this.spatialState.fov = Math.max(0.5, Math.min(Math.PI * 0.8, this.spatialState.fov));
      this.events.onSpatialStateChange(this.spatialState);
    }, { passive: false, signal });

    // Device orientation (mobile)
    if (window.DeviceOrientationEvent) {
      window.addEventListener('deviceorientation', (e) => {
        if (this.spatialState.xrActive) return; // XR handles orientation
        if (e.alpha !== null && e.beta !== null && e.gamma !== null) {
          this.spatialState.yaw = (e.alpha * Math.PI) / 180;
          this.spatialState.pitch = (e.beta! * Math.PI) / 180 - Math.PI / 2;
          this.events.onSpatialStateChange(this.spatialState);
        }
      }, { signal });
    }
  }
}
