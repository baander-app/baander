import type { VisualizerRenderer, RenderContext } from '../types'

/** Particle/ambient flow renderer using Three.js (lazy-loaded).
 *  Creates its own WebGL canvas appended to the host container to avoid
 *  2D/WebGL context conflict on the shared canvas.
 */
export class ParticleRenderer implements VisualizerRenderer {
  readonly id = 'particles' as const
  readonly isWebGL = true

  private threeModule: typeof import('three') | null = null
  private scene: any = null
  private camera: any = null
  private renderer: any = null
  private particles: any = null
  private geometry: any = null
  private width = 0
  private height = 0
  private compact = false
  private container: HTMLElement | null = null
  private ownCanvas: HTMLCanvasElement | null = null
  private hostCanvas: HTMLCanvasElement | null = null
  private initialized = false

  async init(canvas: HTMLCanvasElement, context: { width: number; height: number; compact: boolean }): Promise<void> {
    this.width = context.width
    this.height = context.height
    this.compact = context.compact
    this.container = canvas.parentElement
    this.hostCanvas = canvas

    try {
      const THREE = await import('three')
      this.threeModule = THREE

      // Hide the host's shared canvas (2D) — we create our own WebGL canvas
      canvas.style.display = 'none'

      // Create dedicated WebGL canvas
      this.ownCanvas = document.createElement('canvas')
      this.ownCanvas.style.cssText = 'position:absolute;inset:0;width:100%;height:100%'
      this.container?.appendChild(this.ownCanvas)

      // Scene
      this.scene = new THREE.Scene()

      // Camera
      this.camera = new THREE.PerspectiveCamera(60, this.width / this.height, 0.1, 1000)
      this.camera.position.z = 50

      // WebGL renderer
      this.renderer = new THREE.WebGLRenderer({
        canvas: this.ownCanvas,
        antialias: !this.compact,
        alpha: true,
      })
      this.renderer.setSize(this.width, this.height)
      this.renderer.setPixelRatio(this.compact ? 1 : window.devicePixelRatio)
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
        blending: THREE.AdditiveBlending,
        depthWrite: false,
        color: 0xffffff,
      })

      this.particles = new THREE.Points(this.geometry, material)
      this.scene.add(this.particles)

      this.initialized = true
    } catch (error) {
      console.error('[ParticleRenderer] Failed to initialize Three.js:', error)
      this.threeModule = null
    }
  }

  render(context: RenderContext): void {
    if (!this.initialized || !this.threeModule || !this.renderer || !this.scene || !this.camera) return

    const { data, palette } = context
    const THREE = this.threeModule

    const rms = data.rms
    const spectralCentroid = data.spectralCentroid / 24000

    const positions = this.geometry.attributes.position.array as Float32Array
    const velocities = this.geometry.attributes.aVelocity.array as Float32Array
    const particleCount = positions.length / 3

    const energyScale = 1 + rms * 5

    for (let i = 0; i < particleCount; i++) {
      const i3 = i * 3

      positions[i3] += velocities[i3] * energyScale
      positions[i3 + 1] += velocities[i3 + 1] * energyScale
      positions[i3 + 2] += velocities[i3 + 2] * energyScale

      if (Math.abs(positions[i3]) > 50) positions[i3] *= -0.9
      if (Math.abs(positions[i3 + 1]) > 50) positions[i3 + 1] *= -0.9
      if (Math.abs(positions[i3 + 2]) > 25) positions[i3 + 2] *= -0.9

      const angle = spectralCentroid * 0.002
      const x = positions[i3]
      const z = positions[i3 + 2]
      positions[i3] = x * Math.cos(angle) - z * Math.sin(angle)
      positions[i3 + 2] = x * Math.sin(angle) + z * Math.cos(angle)
    }

    this.geometry.attributes.position.needsUpdate = true

    if (palette && this.particles.material) {
      const primary = new THREE.Color(palette.primary)
      const secondary = new THREE.Color(palette.secondary)
      this.particles.material.color.copy(primary).lerp(secondary, rms)
    }

    if (this.particles.material) {
      this.particles.material.opacity = 0.3 + rms * 2
      this.particles.material.size = (this.compact ? 1 : 2) * (1 + rms * 3)
    }

    this.camera.position.x = Math.sin(Date.now() * 0.0003) * 3
    this.camera.position.y = Math.cos(Date.now() * 0.0002) * 2
    this.camera.lookAt(0, 0, 0)

    this.renderer.render(this.scene, this.camera)
  }

  resize(width: number, height: number): void {
    this.width = width
    this.height = height

    if (this.renderer) this.renderer.setSize(width, height)
    if (this.camera) {
      this.camera.aspect = width / height
      this.camera.updateProjectionMatrix()
    }
  }

  destroy(): void {
    if (this.geometry) {
      this.geometry.dispose()
      this.geometry = null
    }
    if (this.particles?.material) {
      this.particles.material.dispose()
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
    if (this.hostCanvas) {
      this.hostCanvas.style.display = ''
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
