cmake_minimum_required(VERSION 3.15)

# Enforce out-of-source builds — all artifacts under build/
if(CMAKE_CURRENT_SOURCE_DIR STREQUAL CMAKE_CURRENT_BINARY_DIR)
    message(FATAL_ERROR
        "In-source builds are disabled. Use a build/ subdirectory:\n"
        "  mkdir -p build && cd build && cmake .. && make"
    )
endif()

project(baander-aac VERSION 0.1.0 LANGUAGES C CXX)
set(CMAKE_C_STANDARD 11)
set(CMAKE_CXX_STANDARD 17)
set(CMAKE_CXX_STANDARD_REQUIRED ON)

option(BAAC_AAC_BUILD_ENCODER "Build AAC encoder (OFF for WASM)" ON)
option(BAAC_AAC_BUILD_DECODER "Build AAC decoder" ON)
option(BAAC_AAC_BUILD_TESTS   "Build test suite" ON)
option(BAAC_AAC_SHARED        "Build shared lib" OFF)

if(CMAKE_SYSTEM_NAME STREQUAL "iOS")
    set(BAAC_AAC_PLATFORM "ios")
    set(CMAKE_OSX_ARCHITECTURES "arm64")
elseif(CMAKE_SYSTEM_NAME STREQUAL "Android")
    set(BAAC_AAC_PLATFORM "android")
elseif(CMAKE_SYSTEM_NAME STREQUAL "Emscripten")
    set(BAAC_AAC_PLATFORM "wasm")
    set(BAAC_AAC_BUILD_ENCODER OFF)
    set(BAAC_AAC_BUILD_TESTS OFF)
elseif(APPLE)
    set(BAAC_AAC_PLATFORM "macos")
    set(CMAKE_OSX_ARCHITECTURES "x86_64;arm64")
elseif(WIN32)
    set(BAAC_AAC_PLATFORM "windows")
else()
    set(BAAC_AAC_PLATFORM "linux")
endif()

set(BAAC_AAC_SOURCES
    src/tables.cpp src/aac_cpu.cpp src/fft.cpp src/mdct.cpp
    src/bitstream.cpp src/spectral.cpp src/api.cpp)
set(BAAC_AAC_DECODER_SOURCES src/decoder.cpp src/sbr.cpp src/sbr_dec.cpp src/ps.cpp)
set(BAAC_AAC_ENCODER_SOURCES src/psycho.cpp src/encoder.cpp src/sbr_enc.cpp)

set(BAAC_AAC_SIMD_SOURCES)
if(BAAC_AAC_PLATFORM STREQUAL "wasm")
    list(APPEND BAAC_AAC_SIMD_SOURCES src/simd/wasm.cpp)
elseif(CMAKE_SYSTEM_PROCESSOR MATCHES "x86_64|amd64|AMD64|i686|i386")
    list(APPEND BAAC_AAC_SIMD_SOURCES src/simd/sse2.cpp src/simd/avx2.cpp)
elseif(CMAKE_SYSTEM_PROCESSOR MATCHES "aarch64|arm64|arm")
    list(APPEND BAAC_AAC_SIMD_SOURCES src/simd/neon.cpp)
endif()

set(ALL_SOURCES ${BAAC_AAC_SOURCES} ${BAAC_AAC_SIMD_SOURCES})
if(BAAC_AAC_BUILD_DECODER)
    list(APPEND ALL_SOURCES ${BAAC_AAC_DECODER_SOURCES})
endif()
if(BAAC_AAC_BUILD_ENCODER)
    list(APPEND ALL_SOURCES ${BAAC_AAC_ENCODER_SOURCES})
endif()

add_compile_options(
    $<$<CONFIG:Release>:-O3>
    $<$<CONFIG:Release>:-ffast-math>
    $<$<CONFIG:Release>:-fno-math-errno>)

if(NOT BAAC_AAC_PLATFORM STREQUAL "wasm")
    include(CheckCXXCompilerFlag)
    check_cxx_compiler_flag("-msse2" HAS_SSE2)
    if(HAS_SSE2 AND CMAKE_SYSTEM_PROCESSOR MATCHES "x86_64|amd64|AMD64|i686|i386")
        add_compile_options(-msse2)
        add_compile_definitions(BAAC_AAC_SSE2)
    endif()
    check_cxx_compiler_flag("-mavx2" HAS_AVX2)
    if(HAS_AVX2)
        add_compile_options($<$<CONFIG:Release>:-mavx2>)
        add_compile_definitions(BAAC_AAC_AVX2)
    endif()
    check_cxx_compiler_flag("-mfma" HAS_FMA)
    if(HAS_FMA)
        add_compile_options($<$<CONFIG:Release>:-mfma>)
    endif()
    if(CMAKE_SYSTEM_PROCESSOR MATCHES "aarch64|arm64")
        add_compile_definitions(BAAC_AAC_NEON)
    endif()
endif()

if(BAAC_AAC_PLATFORM STREQUAL "wasm")
    add_compile_options(-msimd128 -fno-exceptions -fno-rtti)
    add_compile_definitions(BAAC_AAC_WASM BAAC_AAC_WASM_SIMD128)
endif()

# Per-file compile flags for SIMD sources — must compile with ISA enabled
# regardless of build type (Debug/Release) for runtime dispatch to work.
if(HAS_AVX2 AND CMAKE_SYSTEM_PROCESSOR MATCHES "x86_64|amd64|AMD64|i686|i386")
    set_source_files_properties(src/simd/avx2.cpp PROPERTIES COMPILE_FLAGS "-mavx2 -mfma -mbmi2")
endif()
if(HAS_SSE2 AND CMAKE_SYSTEM_PROCESSOR MATCHES "x86_64|amd64|AMD64|i686|i386")
    set_source_files_properties(src/simd/sse2.cpp PROPERTIES COMPILE_FLAGS "-msse2")
endif()

set(CMAKE_C_VISIBILITY_PRESET hidden)
set(CMAKE_CXX_VISIBILITY_PRESET hidden)
set(CMAKE_VISIBILITY_INLINES_HIDDEN ON)

# ── WASM: executable (emcc produces .wasm + .js) ──────────────────
# ── Native: static/shared library ─────────────────────────────────
if(BAAC_AAC_PLATFORM STREQUAL "wasm")
    add_executable(baander-aac ${ALL_SOURCES})
    target_include_directories(baander-aac PRIVATE
        ${CMAKE_CURRENT_SOURCE_DIR}/include)
    target_link_options(baander-aac PRIVATE
        -s STANDALONE_WASM=1 --no-entry -s ENVIRONMENT=web -s STRICT=1
        -s INITIAL_MEMORY=33554432 -s ALLOW_MEMORY_GROWTH=1
        -s EXPORTED_FUNCTIONS='["_aac_decoder_create","_aac_decoder_destroy","_aac_decoder_decode","_aac_decoder_frame_size","_aac_decoder_sample_rate","_aac_decoder_channels","_aac_decoder_get_sbr_ps"]'
        -s DISABLE_EXCEPTION_CATCHING=1)
    # emcc outputs baander-aac.js + baander-aac.wasm
    set_target_properties(baander-aac PROPERTIES
        SUFFIX ".js"
        RUNTIME_OUTPUT_DIRECTORY "${CMAKE_BINARY_DIR}")
else()
    set(LIB_TYPE STATIC)
    if(BAAC_AAC_SHARED)
        set(LIB_TYPE SHARED)
    endif()
    add_library(baander-aac ${LIB_TYPE} ${ALL_SOURCES})
    target_include_directories(baander-aac PUBLIC
        $<BUILD_INTERFACE:${CMAKE_CURRENT_SOURCE_DIR}/include>
        $<INSTALL_INTERFACE:include>)
    if(UNIX)
        target_link_libraries(baander-aac PRIVATE m)
    endif()
endif()

# ── IDE helper: index all SIMD sources regardless of platform ────
# neon.cpp / wasm.cpp are excluded from the build on x86, so CLion
# won't index them. This STATIC library with EXCLUDE_FROM_ALL makes
# every source visible to the IDE without affecting the real build.
if(NOT BAAC_AAC_PLATFORM STREQUAL "wasm")
    add_library(baander-aac-simdeps STATIC
        EXCLUDE_FROM_ALL
        src/simd/sse2.cpp
        src/simd/avx2.cpp
        src/simd/neon.cpp
        src/simd/wasm.cpp)
    target_include_directories(baander-aac-simdeps PRIVATE
        ${CMAKE_CURRENT_SOURCE_DIR}/include)
endif()

include(GNUInstallDirs)
install(TARGETS baander-aac
    LIBRARY DESTINATION ${CMAKE_INSTALL_LIBDIR}
    ARCHIVE DESTINATION ${CMAKE_INSTALL_LIBDIR})
install(DIRECTORY include/ DESTINATION ${CMAKE_INSTALL_INCLUDEDIR}
    FILES_MATCHING PATTERN "*.h")

if(BAAC_AAC_BUILD_TESTS)
    enable_testing()
    add_subdirectory(tests)
endif()
