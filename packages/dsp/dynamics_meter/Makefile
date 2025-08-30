EMCC ?= emcc
SRC  := dynamics_meter.cpp
WASM := dynamics_meter.wasm

CFLAGS := -O3 -ffast-math -fno-math-errno -msimd128
LDFLAGS := \
  -s STANDALONE_WASM=1 \
  --no-entry \
  -s ENVIRONMENT=web \
  -s STRICT=1 \
  -s INITIAL_MEMORY=16777216 \
  -s ALLOW_MEMORY_GROWTH=0 \
  -s EXPORTED_FUNCTIONS='["_init_meters","_reset_meters","_process_frames","_get_rms_left","_get_rms_right","_get_peak_left","_get_peak_right","_get_crest_left","_get_crest_right"]'

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
