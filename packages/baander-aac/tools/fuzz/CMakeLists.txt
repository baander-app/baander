# The fuzz harnesses, the seed generator and the mutation driver.
#
# Every harness is a plain executable over the mutation driver of
# fuzz_driver.cpp (it replays and mutates a corpus, and records a
# faulting input as a reproducer).  With BAAC_FUZZ the harnesses are
# built with -fsanitize=fuzzer,address,undefined when the compiler has
# libFuzzer (clang), or with the sanitizers alone over the mutation
# driver when it has not; the library is instrumented in the same
# build, so the whole tree is sanitized.

set(FUZZ_HARNESSES fuzz_decoder fuzz_asc fuzz_encoder fuzz_unidrc fuzz_mps)

add_executable(fuzz_seeds fuzz_seeds.cpp)
target_link_libraries(fuzz_seeds PRIVATE baander-aac)

foreach(harness ${FUZZ_HARNESSES})
    if(BAAC_FUZZ_HAVE_LIBFUZZER)
        add_executable(${harness} ${harness}.cpp)
        target_compile_definitions(${harness} PRIVATE BAAC_FUZZ_LIBFUZZER)
        target_link_options(${harness} PRIVATE -fsanitize=fuzzer)
    else()
        add_executable(${harness} ${harness}.cpp fuzz_driver.cpp)
    endif()
    target_link_libraries(${harness} PRIVATE baander-aac)
    # The harnesses check that the output is finite; under the Release
    # build's -ffinite-math-only the compiler would fold that check to
    # true.  The library keeps the flags it ships with.
    if(CMAKE_CXX_COMPILER_ID MATCHES "GNU|Clang")
        target_compile_options(${harness} PRIVATE -fno-finite-math-only)
    endif()
    if(UNIX)
        target_link_libraries(${harness} PRIVATE m)
    endif()
endforeach()
