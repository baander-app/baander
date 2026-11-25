// SPDX-License-Identifier: Apache-2.0
#pragma once

#include <boost/beast/core/flat_buffer.hpp>
#include <cstddef>

namespace registry {

// What the API listener accepts from a client.
inline constexpr std::size_t request_header_limit = 8192;
inline constexpr std::size_t request_body_limit = 8192;
// What the database client accepts from rqlite.
inline constexpr std::size_t response_header_limit = 8192;
inline constexpr std::size_t response_body_limit = 65536;
// Beast's parser consumes a complete header block, and body bytes as they arrive, so a read
// buffer has to hold at most one header block or one chunk-size line. The header and body
// limits do not cover a chunk-size line: the parser leaves it in the buffer until its CRLF
// arrives, so an unterminated chunk extension grows an unbounded buffer for as long as the
// peer keeps sending. This bound ends that read with http::error::buffer_overflow.
inline constexpr std::size_t read_buffer_limit = 16384;

inline boost::beast::flat_buffer bounded_read_buffer() {
    return boost::beast::flat_buffer{read_buffer_limit};
}

} // namespace registry
