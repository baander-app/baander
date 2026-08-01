EMCC ?= emcc
SRC  := spectral_features.cpp
WASM := spectral_features.wasm

CFLAGS := -O3 -ffast-math -fno-math-errno -msimd128
LDFLAGS := \
  -s STANDALONE_WASM=1 \
  --no-entry \
  -s ENVIRONMENT=web \
  -s STRICT=1 \
  -s INITIAL_MEMORY=16777216 \
  -s ALLOW_MEMORY_GROWTH=0 \
  -s EXPORTED_FUNCTIONS='["_wasm_malloc","_wasm_free","_init_features","_compute_from_mag","_get_centroid_hz","_get_rolloff_hz","_get_flux","_get_flatness","_get_peak_index","_get_band_energies"]'

.PHONY: all build clean serve-demo
all: build
build: $(WASM)
$(WASM): $(SRC)
	$(EMCC) $(SRC) $(CFLAGS) $(LDFLAGS) -o $(WASM)
	@echo "Built $(WASM)"
clean:
	@rm -f $(WASM)
serve-demo:
	@echo "Serving at http://localhost:8000"
	python3 -m http.server 8000
