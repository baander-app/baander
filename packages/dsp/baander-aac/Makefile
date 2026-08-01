# Baander AAC WASM Decoder Makefile
# Wraps the CMake WASM build from packages/codecs/baander-aac/
# Follows the same pattern as other packages/dsp/ Makefiles.

EMCC ?= emcc
CMAKE ?= cmake
CODEC_DIR := ../../codecs/baander-aac
BUILD_DIR := build-wasm
WASM := baander-aac.wasm
JS := baander-aac.js

# Source files for dependency tracking
SOURCES := $(shell find $(CODEC_DIR)/src -name '*.cpp' -o -name '*.h' 2>/dev/null)
HEADERS := $(shell find $(CODEC_DIR)/include -name '*.h' 2>/dev/null)

.PHONY: all build clean

all: build

build: $(WASM)

$(WASM): $(SOURCES) $(HEADERS) Makefile
	@mkdir -p $(BUILD_DIR)
	cd $(BUILD_DIR) && $(CMAKE) \
		-DCMAKE_TOOLCHAIN_FILE=$$EMSDK/upstream/emscripten/cmake/Modules/Platform/Emscripten.cmake \
		-DBAAC_AAC_BUILD_ENCODER=OFF \
		-DBAAC_AAC_BUILD_TESTS=OFF \
		$(CURDIR)/$(CODEC_DIR)
	$(CMAKE) --build $(BUILD_DIR)
	cp $(BUILD_DIR)/baander-aac.wasm $(WASM)
	cp $(BUILD_DIR)/baander-aac.js $(JS) 2>/dev/null || true

clean:
	@rm -rf $(BUILD_DIR) $(WASM) $(JS)
