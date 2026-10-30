cmake_minimum_required(VERSION 3.15)

# Enforce out-of-source builds.  build/ belongs to node-gyp (the Node-API
# binding), so CMake builds go to cmake-build/.
if(CMAKE_CURRENT_SOURCE_DIR STREQUAL CMAKE_CURRENT_BINARY_DIR)
    message(FATAL_ERROR
        "In-source builds are disabled. Use a cmake-build/ subdirectory:\n"
        "  mkdir -p cmake-build && cd cmake-build && cmake .. && make"
    )
endif()

project(baander-aac VERSION 0.1.0 LANGUAGES C CXX)
set(CMAKE_C_STANDARD 11)
set(CMAKE_CXX_STANDARD 17)
set(CMAKE_CXX_STANDARD_REQUIRED ON)

option(BAAC_AAC_BUILD_ENCODER "Build AAC encoder" ON)
option(BAAC_AAC_BUILD_DECODER "Build AAC decoder" ON)
option(BAAC_AAC_BUILD_TESTS   "Build test suite" ON)
option(BAAC_AAC_BUILD_TOOLS   "Build the baac command-line tool" ON)
option(BAAC_AAC_SHARED        "Build shared lib" OFF)

if(CMAKE_SYSTEM_NAME STREQUAL "iOS")
    set(BAAC_AAC_PLATFORM "ios")
    set(CMAKE_OSX_ARCHITECTURES "arm64")
elseif(CMAKE_SYSTEM_NAME STREQUAL "Android")
    set(BAAC_AAC_PLATFORM "android")
elseif(APPLE)
    set(BAAC_AAC_PLATFORM "macos")
    set(CMAKE_OSX_ARCHITECTURES "x86_64;arm64")
elseif(WIN32)
    set(BAAC_AAC_PLATFORM "windows")
else()
    set(BAAC_AAC_PLATFORM "linux")
endif()

set(BAAC_AAC_SOURCES
    src/tables.cpp src/aac_cpu.cpp src/dsp.cpp src/fft.cpp src/mdct.cpp
    src/ics.cpp src/bitstream.cpp src/channel_map.cpp src/qmf.cpp src/sbr_tables.cpp src/sbr_freq.cpp src/ps_hybrid.cpp
    src/asc.cpp src/latm.cpp src/transport.cpp src/drc.cpp src/api.cpp
    src/lowdelay.cpp src/eld_tables.cpp src/qmf_ld.cpp src/sbr_ld.cpp src/usac_config.cpp
    src/unidrc_parse.cpp src/unidrc_gain.cpp src/unidrc.cpp
    src/usac_arith_tables.cpp src/usac_arith.cpp src/usac_stereo.cpp src/usac_fd.cpp)
set(BAAC_AAC_DECODER_SOURCES src/decoder.cpp src/conceal.cpp src/decoder_output.cpp src/limiter.cpp src/sbr_dec.cpp src/sbr_esbr.cpp src/ps_dec.cpp
    src/er_vcb11.cpp src/er_rvlc.cpp src/er_hcr.cpp
    src/usac_frame.cpp src/mps212.cpp src/mps212_tables.cpp
    src/mps_tables.cpp src/mps_tree.cpp src/mps_dec.cpp
    src/mps_params.cpp src/mps_config.cpp src/mps_frame.cpp src/mps_glue.cpp
    src/usac_lpd.cpp src/usac_lpc.cpp src/usac_acelp.cpp src/usac_tcx.cpp src/usac_fac.cpp
    src/usac_lpd_tables.cpp)
set(BAAC_AAC_ENCODER_SOURCES src/psycho.cpp src/encoder_tools.cpp src/afterburner.cpp src/encoder.cpp src/sbr_enc.cpp src/ps_enc.cpp
    src/sbr_ld_enc.cpp)

set(BAAC_AAC_SIMD_SOURCES)
if(CMAKE_SYSTEM_PROCESSOR MATCHES "x86_64|amd64|AMD64|i686|i386")
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

include(CheckCXXCompilerFlag)
check_cxx_compiler_flag("-msse2" HAS_SSE2)
if(HAS_SSE2 AND CMAKE_SYSTEM_PROCESSOR MATCHES "x86_64|amd64|AMD64|i686|i386")
    add_compile_options(-msse2)
    add_compile_definitions(BAAC_AAC_SSE2)
endif()
# AVX2 is enabled only for src/simd/avx2.cpp (below) and selected at
# runtime; compiling everything with -mavx2 would make the library fault on
# CPUs without it.
check_cxx_compiler_flag("-mavx2" HAS_AVX2)
if(HAS_AVX2 AND CMAKE_SYSTEM_PROCESSOR MATCHES "x86_64|amd64|AMD64|i686|i386")
    add_compile_definitions(BAAC_AAC_AVX2)
endif()
if(CMAKE_SYSTEM_PROCESSOR MATCHES "aarch64|arm64")
    add_compile_definitions(BAAC_AAC_NEON)
endif()

if(BAAC_AAC_BUILD_DECODER)
    add_compile_definitions(BAAC_AAC_HAVE_DECODER)
endif()
if(BAAC_AAC_BUILD_ENCODER)
    add_compile_definitions(BAAC_AAC_HAVE_ENCODER)
endif()

# Per-file compile flags for SIMD sources — must compile with ISA enabled
# regardless of build type (Debug/Release) for runtime dispatch to work.
# Contraction is off in these files: the transform kernels must give the
# same bits as the scalar ones, and a compiler that fuses a product and a
# sum into one FMA instruction would change the rounding.  Kernels that
# want a fused multiply-add ask for it with the intrinsic.
if(HAS_AVX2 AND CMAKE_SYSTEM_PROCESSOR MATCHES "x86_64|amd64|AMD64|i686|i386")
    set_source_files_properties(src/simd/avx2.cpp PROPERTIES
        COMPILE_FLAGS "-mavx2 -mfma -mbmi2 -ffp-contract=off")
endif()
if(HAS_SSE2 AND CMAKE_SYSTEM_PROCESSOR MATCHES "x86_64|amd64|AMD64|i686|i386")
    set_source_files_properties(src/simd/sse2.cpp PROPERTIES COMPILE_FLAGS "-msse2 -ffp-contract=off")
endif()
if(CMAKE_SYSTEM_PROCESSOR MATCHES "aarch64|arm64|arm")
    set_source_files_properties(src/simd/neon.cpp PROPERTIES COMPILE_FLAGS "-ffp-contract=off")
endif()
# The scalar kernels the SIMD ones are compared against follow the same
# rule (on x86-64 without FMA this changes nothing; on ARM it keeps the
# scalar table in step with NEON).
set_source_files_properties(src/dsp.cpp PROPERTIES COMPILE_FLAGS "-ffp-contract=off")

set(CMAKE_C_VISIBILITY_PRESET hidden)
set(CMAKE_CXX_VISIBILITY_PRESET hidden)
set(CMAKE_VISIBILITY_INLINES_HIDDEN ON)

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

# ── IDE helper: index all SIMD sources regardless of platform ────
# neon.cpp is excluded from the build on x86, so CLion won't index it.
# This STATIC library with EXCLUDE_FROM_ALL makes every source visible
# to the IDE without affecting the real build.
add_library(baander-aac-simdeps STATIC
    EXCLUDE_FROM_ALL
    src/simd/sse2.cpp
    src/simd/avx2.cpp
    src/simd/neon.cpp)
target_include_directories(baander-aac-simdeps PRIVATE
    ${CMAKE_CURRENT_SOURCE_DIR}/include)

include(GNUInstallDirs)
install(TARGETS baander-aac
    LIBRARY DESTINATION ${CMAKE_INSTALL_LIBDIR}
    ARCHIVE DESTINATION ${CMAKE_INSTALL_LIBDIR})
install(DIRECTORY include/ DESTINATION ${CMAKE_INSTALL_INCLUDEDIR}
    FILES_MATCHING PATTERN "*.h")

if(BAAC_AAC_BUILD_TOOLS AND BAAC_AAC_BUILD_ENCODER AND BAAC_AAC_BUILD_DECODER)
    add_executable(baac tools/baac.cpp)
    target_link_libraries(baac PRIVATE baander-aac)
    install(TARGETS baac RUNTIME DESTINATION ${CMAKE_INSTALL_BINDIR})
    # MPEG Surround test encoder: 5.1 PCM to a LOAS stream with an AAC-LC
    # downmix and a spatial payload, for conformance checks against
    # reference decoders.
    add_executable(mps-enc tools/mps-enc.cpp)
    target_include_directories(mps-enc PRIVATE src)
    target_link_libraries(mps-enc PRIVATE baander-aac)
endif()

if(BAAC_AAC_BUILD_TESTS)
    enable_testing()
    add_subdirectory(tests)
endif()
