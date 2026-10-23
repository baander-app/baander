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
