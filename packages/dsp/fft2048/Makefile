# Build and demo for fft2048 WASM
# Requires: Emscripten (emcc) and Python 3

EMCC ?= emcc
SRC  := fft2048.cpp
WASM := fft2048.wasm

CFLAGS := -O3 -ffast-math -fno-math-errno -msimd128
LDFLAGS := \
  -s STANDALONE_WASM=1 \
  --no-entry \
  -s ENVIRONMENT=web \
  -s STRICT=1 \
  -s INITIAL_MEMORY=16777216 \
  -s ALLOW_MEMORY_GROWTH=0 \
  -s EXPORTED_FUNCTIONS='["_wasm_malloc","_wasm_free","_init_fft","_reset_fft","_process_spectrum","_push_block","_consume_spectrum","_downsample_mag_stride4","_downsample_wave_stride4"]'

.PHONY: all build clean serve-demo

all: build

build: $(WASM)

$(WASM): $(SRC)
	$(EMCC) $(SRC) $(CFLAGS) $(LDFLAGS) -o $(WASM)
	@echo "Built $(WASM)"

clean:
	@rm -f $(WASM)

serve-demo:
	@echo "Serving demo at http://localhost:8000"
	python3 -m http.server 8000
