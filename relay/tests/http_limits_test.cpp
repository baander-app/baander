// SPDX-License-Identifier: Apache-2.0
#include "registry/http_limits.hpp"
#include <boost/asio/io_context.hpp>
#include <boost/beast/_experimental/test/stream.hpp>
#include <boost/beast/http.hpp>
#include <gtest/gtest.h>
#include <sstream>
#include <string>

namespace registry {
namespace {
namespace beast = boost::beast;
namespace http = beast::http;
constexpr std::size_t mebibyte = 1024 * 1024;
const std::string request_head = "POST /api/servers/register HTTP/1.1\r\n"
                                 "Host: api.registry.baander.app\r\n"
                                 "Transfer-Encoding: chunked\r\n\r\n";
const std::string response_head = "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n";

void limit(http::request_parser<http::string_body> &parser) {
    parser.header_limit(request_header_limit);
    parser.body_limit(request_body_limit);
}
void limit(http::response_parser<http::string_body> &parser) {
    parser.header_limit(response_header_limit);
    parser.body_limit(response_body_limit);
}
// Reads one message from a peer that sends `wire` and then closes its side.
template <class Parser>
beast::error_code read(const std::string &wire, beast::flat_buffer &buffer, Parser &parser) {
    limit(parser);
    boost::asio::io_context context;
    beast::test::stream stream(context, wire);
    stream.close_remote();
    beast::error_code error;
    http::read(stream, buffer, parser, error);
    return error;
}
std::string unterminated_extension(std::size_t bytes) { return "1;" + std::string(bytes, 'a'); }
std::string hex(std::size_t value) {
    std::ostringstream text;
    text << std::hex << value;
    return text.str();
}
std::string chunked(const std::string &body, std::size_t chunk) {
    std::string wire;
    for (std::size_t offset = 0; offset < body.size(); offset += chunk) {
        const auto part = body.substr(offset, chunk);
        wire += hex(part.size()) + "\r\n" + part + "\r\n";
    }
    return wire + "0\r\n\r\n";
}
} // namespace

TEST(ReadBuffer, UnterminatedChunkExtensionGrowsAnUnboundedRequestBuffer) {
    beast::flat_buffer unbounded;
    http::request_parser<http::string_body> parser;
    EXPECT_EQ(http::error::partial_message,
              read(request_head + unterminated_extension(mebibyte), unbounded, parser));
    EXPECT_GE(unbounded.size(), mebibyte);
}
TEST(ReadBuffer, UnterminatedChunkExtensionOverflowsTheBoundedRequestBuffer) {
    auto bounded = bounded_read_buffer();
    http::request_parser<http::string_body> parser;
    EXPECT_EQ(http::error::buffer_overflow,
              read(request_head + unterminated_extension(mebibyte), bounded, parser));
    EXPECT_LE(bounded.size(), read_buffer_limit);
}
TEST(ReadBuffer, UnterminatedChunkExtensionOverflowsTheBoundedResponseBuffer) {
    beast::flat_buffer unbounded;
    http::response_parser<http::string_body> grown;
    EXPECT_EQ(http::error::partial_message,
              read(response_head + unterminated_extension(mebibyte), unbounded, grown));
    EXPECT_GE(unbounded.size(), mebibyte);
    auto bounded = bounded_read_buffer();
    http::response_parser<http::string_body> parser;
    EXPECT_EQ(http::error::buffer_overflow,
              read(response_head + unterminated_extension(mebibyte), bounded, parser));
    EXPECT_LE(bounded.size(), read_buffer_limit);
}
// The parser applies the remaining header budget to trailer fields, so this growth path is
// closed even without the buffer bound.
TEST(ReadBuffer, HeaderLimitBoundsAnUnterminatedTrailer) {
    beast::flat_buffer unbounded;
    http::request_parser<http::string_body> parser;
    EXPECT_EQ(
        http::error::header_limit,
        read(request_head + "0\r\nX-Trailer: " + std::string(mebibyte, 'a'), unbounded, parser));
    EXPECT_LT(unbounded.size(), mebibyte / 8);
}
TEST(ReadBuffer, LargestRequestsFitTheBoundedBuffer) {
    const std::string body(request_body_limit, 'b');
    for (const auto &wire :
         {"POST /api/servers/register HTTP/1.1\r\nHost: api.registry.baander.app\r\n"
          "Content-Length: " +
              std::to_string(body.size()) + "\r\n\r\n" + body,
          request_head + chunked(body, request_body_limit)}) {
        auto bounded = bounded_read_buffer();
        http::request_parser<http::string_body> parser;
        EXPECT_FALSE(read(wire, bounded, parser));
        EXPECT_EQ(body, parser.get().body());
    }
    auto bounded = bounded_read_buffer();
    http::request_parser<http::string_body> parser;
    EXPECT_EQ(http::error::body_limit,
              read(request_head + chunked(body + "b", request_body_limit + 1), bounded, parser));
}
TEST(ReadBuffer, LargestResponsesFitTheBoundedBuffer) {
    const std::string body(response_body_limit, 'r');
    const std::string headers(response_header_limit - 512, 'h');
    for (const auto &wire : {"HTTP/1.1 200 OK\r\nX-Padding: " + headers + "\r\nContent-Length: " +
                                 std::to_string(body.size()) + "\r\n\r\n" + body,
                             response_head + chunked(body, 4096)}) {
        auto bounded = bounded_read_buffer();
        http::response_parser<http::string_body> parser;
        EXPECT_FALSE(read(wire, bounded, parser));
        EXPECT_EQ(body, parser.get().body());
    }
}
} // namespace registry
