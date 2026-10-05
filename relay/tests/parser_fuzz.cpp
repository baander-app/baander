// SPDX-License-Identifier: Apache-2.0
#include "registry/transport.hpp"
#include <cstddef>
#include <cstdint>
#include <string>

extern "C" int LLVMFuzzerTestOneInput(const std::uint8_t *data, std::size_t size) {
    // Explicit length retains embedded NULs, including bytes after a valid value.
    const std::string body(reinterpret_cast<const char *>(data), size);
    static const registry::Registration enrollment_registration =
        registry::validate_registration(registry::Json{
            {"publicId", "fuzz-server"},
            {"url", "https://fuzz.baander.app"},
            {"name", "Fuzz server"},
            {"version", "1.0.0"},
            {"apiKey", std::string(64, 'a')},
        });

    [[maybe_unused]] const bool enrolled = registry::validate_enrollment(
        enrollment_registration,
        body,
        std::string(32, 'k'),
        1000);

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
