# Test build configuration for baander-aac

# Test sources
set(TEST_SOURCES
    test_mdct.cpp
    test_bitstream.cpp
    test_roundtrip.cpp
    test_quality.cpp
    test_real_songs.cpp
)

# Create test executables
foreach(test_src ${TEST_SOURCES})
    get_filename_component(test_name ${test_src} NAME_WE)
    add_executable(${test_name} ${test_src})
    target_link_libraries(${test_name} PRIVATE baander-aac)
    if(UNIX)
        target_link_libraries(${test_name} PRIVATE m)
    endif()
    add_test(NAME ${test_name} COMMAND ${test_name})
endforeach()
