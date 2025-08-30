EMCC ?= emcc
SRC  := loudness_r128.cpp
WASM := loudness_r128.wasm

CFLAGS := -O3 -ffast-math -fno-math-errno -msimd128
LDFLAGS := \
  -s STANDALONE_WASM=1 \
  --no-entry \
  -s ENVIRONMENT=web \
  -s STRICT=1 \
  -s INITIAL_MEMORY=33554432 \
  -s ALLOW_MEMORY_GROWTH=0 \
  -s EXPORTED_FUNCTIONS='["_init_loudness","_reset_loudness","_process_frames","_get_lufs_momentary","_get_lufs_shortterm","_get_lufs_integrated","_get_lra","_get_true_peak_dbfs"]' \
  -lc++ -lc++abi
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
