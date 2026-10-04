// SPDX-License-Identifier: Apache-2.0
#include "registry/transport.hpp"
#include <gtest/gtest.h>

namespace registry {
TEST(JsonBoundary, ValidDepthAndSize) {
    EXPECT_EQ(parse_json_body("{\"value\":[1]}", 8192, 8)["value"][0], 1);
    EXPECT_EQ(parse_json_body("[[[[[[[[0]]]]]]]]", 8192, 8).size(), 1);
}
TEST(JsonBoundary, RejectsMalformedAndExcessiveDepthDuringParsing) {
    for (const auto &body : {"{", "[[[[[[[[[0]]]]]]]]]"}) {
        try {
            parse_json_body(body, 8192, 8);
            FAIL() << "Expected malformed/deep JSON rejection";
        } catch (const Failure &failure) {
            EXPECT_EQ(failure.status, 400u);
        }
    }
    // No complete DOM is allocated for arbitrarily deep input.
    const std::string deep(4000, '[');
    EXPECT_THROW(parse_json_body(deep, 8192, 8), Failure);
}
TEST(JsonBoundary, RejectsBodyBeforeParsing) {
    try {
        parse_json_body(std::string(8193, ' '), 8192, 8);
        FAIL() << "Expected body size rejection";
    } catch (const Failure &failure) {
        EXPECT_EQ(failure.status, 413u);
    }
}
} // namespace registry
