# Test build configuration for baander-aac.  Every test is a standalone
# executable over the public API (plus the internal headers for the unit
# tests) and returns nonzero on failure.

set(TEST_SOURCES
    test_tables.cpp
    test_mdct.cpp
    test_bitstream.cpp
    test_decoder_compliance.cpp
    test_roundtrip.cpp
    test_ratecontrol.cpp
    test_tools.cpp
    test_interop.cpp
    test_layouts.cpp
    test_qmf.cpp
    test_limiter.cpp
    test_conceal.cpp
    test_frame960.cpp
    test_transport.cpp
    test_drc.cpp
    test_afterburner.cpp
    test_sbr_ld.cpp
    test_lowdelay.cpp
    test_eld_sbr.cpp
    test_dsp.cpp
    test_allocation.cpp
    test_er.cpp
    test_usac_config.cpp
    test_mps212.cpp
)

foreach(test_src ${TEST_SOURCES})
    get_filename_component(test_name ${test_src} NAME_WE)
    add_executable(${test_name} ${test_src})
    target_link_libraries(${test_name} PRIVATE baander-aac)
    target_compile_definitions(${test_name} PRIVATE
        BAAC_TEST_DATA_DIR="${CMAKE_CURRENT_SOURCE_DIR}/data")
    if(UNIX)
        target_link_libraries(${test_name} PRIVATE m)
    endif()
    add_test(NAME ${test_name} COMMAND ${test_name})
endforeach()

# The transforms and filterbanks go through the DSP dispatch table; the
# same suites run again with the table capped at the scalar and the SSE2
# kernels (BAAC_AAC_CPU), so that every level this machine can run is
# exercised end to end, not only in the kernel comparison of test_dsp.
if(CMAKE_SYSTEM_PROCESSOR MATCHES "x86_64|amd64|AMD64|i686|i386")
    set(BAAC_DISPATCH_LEVELS scalar sse2)
else()
    set(BAAC_DISPATCH_LEVELS scalar)
endif()
foreach(level ${BAAC_DISPATCH_LEVELS})
    foreach(test_name test_mdct test_qmf test_roundtrip)
        add_test(NAME ${test_name}_${level} COMMAND ${test_name})
        set_tests_properties(${test_name}_${level} PROPERTIES ENVIRONMENT "BAAC_AAC_CPU=${level}")
    endforeach()
endforeach()
