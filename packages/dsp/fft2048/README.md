How to use
- Build: cd packages/fft2048 && make build
- Serve demo: cd packages/fft2048 && make serve-demo, then open [http://localhost:8000/packages/fft2048/demo.html](http://localhost:8000/packages/fft2048/demo.html)
- The demo plays a short embedded tone or your microphone, runs the FFT in the worklet/WASM, and renders:
    - 256-bin spectrum
    - 512-sample waveform

Notes
- The worklet throttles posts and sends compact buffers to avoid DataCloneError and GC pressure.
- The Emscripten build is standalone WASM (no JS runtime), so the worklet instantiates it directly and calls exported functions and malloc/free.
