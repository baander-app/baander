// SPDX-License-Identifier: Apache-2.0
#include "registry/transport.hpp"
#include <cstddef>
#include <cstdint>
#include <string>

extern "C" int LLVMFuzzerTestOneInput(const std::uint8_t *data, std::size_t size) {
    // Explicit length retains embedded NULs, including bytes after a valid value.
    const std::string body(reinterpret_cast<const char *>(data), size);
    try {
        const auto parsed = registry::parse_json_body(body, 8192, 8);
        [[maybe_unused]] const auto registration = registry::validate_registration(parsed);
    } catch (const registry::Failure &) {
        // Public input rejection is expected; every other exception is a failure.
    }
    try {
        [[maybe_unused]] const auto parsed = registry::parse_json_body(body, 65536, 8);
    } catch (const registry::Failure &) {
    }
    return 0;
}
