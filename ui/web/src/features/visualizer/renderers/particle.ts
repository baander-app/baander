import type * as THREE from 'three'
import type { VisualizerRenderer, RenderContext } from '../types'
import { aggregateSpectrum } from '../utils/spectrum'

const canvasOwners = new WeakMap<HTMLCanvasElement, ParticleRenderer>()

/** Particle/ambient flow renderer using Three.js (lazy-loaded).
 *  Creates its own WebGL canvas appended to the host container to avoid
 *  2D/WebGL context conflict on the shared canvas.
 */
export class ParticleRenderer implements VisualizerRenderer {
  readonly id = 'particles' as const
  readonly isWebGL = true

  private threeModule: typeof import('three') | null = null
  private scene: THREE.Scene | null = null
  private camera: THREE.PerspectiveCamera | null = null
  private renderer: THREE.WebGLRenderer | null = null
  private particles: THREE.Points | null = null
  private geometry: THREE.BufferGeometry | null = null
  private width = 0
  private height = 0
  private compact = false
  private container: HTMLElement | null = null
  private ownCanvas: HTMLCanvasElement | null = null
  private hostCanvas: HTMLCanvasElement | null = null
  private initialized = false
  private cancelled = false
  private elapsed = 0

  async init(canvas: HTMLCanvasElement, context: { width: number; height: number; compact: boolean }): Promise<void> {
    this.width = context.width
    this.height = context.height
    this.compact = context.compact
    this.container = canvas.parentElement
    this.hostCanvas = canvas

    const THREE = await import('three')
    if (this.cancelled) return
    this.threeModule = THREE

    // Hide the host's shared canvas (2D) — we create our own WebGL canvas
    canvasOwners.set(canvas, this)
    canvas.style.display = 'none'

    // Create dedicated WebGL canvas
    this.ownCanvas = document.createElement('canvas')
    this.ownCanvas.style.cssText = 'position:absolute;inset:0;width:100%;height:100%'
    this.ownCanvas.style.opacity = canvas.style.opacity
    this.container?.appendChild(this.ownCanvas)

    // Scene
    this.scene = new THREE.Scene()

    // Camera
    this.camera = new THREE.PerspectiveCamera(60, this.width / Math.max(1, this.height), 0.1, 1000)
    this.camera.position.z = 50

    // WebGL renderer
    this.renderer = new THREE.WebGLRenderer({
      canvas: this.ownCanvas,
      antialias: !this.compact,
      alpha: true,
    })
    this.renderer.setPixelRatio(this.compact ? 1 : window.devicePixelRatio || 1)
    this.renderer.setSize(this.width, this.height)
    this.renderer.setClearColor(0x000000, 0)

    // Particle system
    const particleCount = this.compact ? 500 : 3000
    this.geometry = new THREE.BufferGeometry()
    const positions = new Float32Array(particleCount * 3)
    const velocities = new Float32Array(particleCount * 3)
    const sizes = new Float32Array(particleCount)

    for (let i = 0; i < particleCount; i++) {
      const i3 = i * 3
      positions[i3] = (Math.random() - 0.5) * 100
      positions[i3 + 1] = (Math.random() - 0.5) * 100
      positions[i3 + 2] = (Math.random() - 0.5) * 50
      velocities[i3] = (Math.random() - 0.5) * 0.1
      velocities[i3 + 1] = (Math.random() - 0.5) * 0.1
      velocities[i3 + 2] = (Math.random() - 0.5) * 0.05
      sizes[i] = Math.random() * 2 + 0.5
    }

    this.geometry.setAttribute('position', new THREE.BufferAttribute(positions, 3))
    this.geometry.setAttribute('aVelocity', new THREE.BufferAttribute(velocities, 3))
    this.geometry.setAttribute('aSize', new THREE.BufferAttribute(sizes, 1))

    const material = new THREE.PointsMaterial({
      size: this.compact ? 1 : 2,
      transparent: true,
      opacity: 0.8,
      blending: THREE.NormalBlending,
      depthWrite: false,
      color: 0x000000,
    })

    this.particles = new THREE.Points(this.geometry, material)
    this.scene.add(this.particles)

    this.initialized = true
  }

  render(context: RenderContext): void {
    if (!this.initialized || !this.threeModule || !this.renderer || !this.scene || !this.camera) return

    const { data, active, colors, deltaTime } = context
    if (!active) {
      this.elapsed = 0
      this.renderer.clear()
      return
    }
    this.elapsed += deltaTime / 1000
    const bands = aggregateSpectrum(data)
    let peak = 0
    for (const value of bands) peak = Math.max(peak, value)

    const positionAttr = this.geometry!.attributes.position as THREE.BufferAttribute
    const velocityAttr = this.geometry!.getAttribute('aVelocity') as THREE.BufferAttribute
    const positions = positionAttr.array as Float32Array
    const velocities = velocityAttr.array as Float32Array
    const energyScale = 1 + data.rms * 5
    const step = deltaTime / (1000 / 60)
    for (let i = 0; i < positions.length; i++) {
      const extent = i % 3 === 2 ? 25 : 50
      const next = positions[i]! + velocities[i]! * energyScale * step
      positions[i] = ((next + extent) % (2 * extent) + 2 * extent) % (2 * extent) - extent
    }
    positionAttr.needsUpdate = true
    this.particles!.rotation.y = this.elapsed * data.spectralCentroid / Math.max(1, data.sampleRate / 2) * 0.12
    const material = this.particles!.material as THREE.PointsMaterial
    material.color.set(colors.accent)
    material.opacity = peak
    material.size = (this.compact ? 1 : 2) * (1 + data.rms * 3)
    this.camera.position.x = Math.sin(this.elapsed * 0.3) * 3
    this.camera.position.y = Math.cos(this.elapsed * 0.2) * 2
    this.camera.lookAt(0, 0, 0)

    this.renderer.render(this.scene, this.camera)
  }

  resize(width: number, height: number): void {
    this.width = width
    this.height = height

    if (this.renderer) {
      this.renderer.setPixelRatio(this.compact ? 1 : window.devicePixelRatio || 1)
      this.renderer.setSize(width, height)
    }
    if (this.camera) {
      this.camera.aspect = width / Math.max(1, height)
      this.camera.updateProjectionMatrix()
    }
  }

  cancel(): void {
    this.cancelled = true
  }

  destroy(): void {
    this.cancel()
    if (this.geometry) {
      this.geometry.dispose()
      this.geometry = null
    }
    if (this.particles?.material) {
      (this.particles.material as THREE.Material).dispose()
    }
    if (this.renderer) {
      this.renderer.dispose()
      this.renderer = null
    }
    // Remove our own canvas
    if (this.ownCanvas) {
      this.ownCanvas.remove()
      this.ownCanvas = null
    }
    // Restore host canvas visibility
    if (this.hostCanvas && canvasOwners.get(this.hostCanvas) === this) {
      this.hostCanvas.style.display = ''
      canvasOwners.delete(this.hostCanvas)
    }
    this.threeModule = null
    this.scene = null
    this.camera = null
    this.particles = null
    this.container = null
    this.hostCanvas = null
    this.initialized = false
  }
}
