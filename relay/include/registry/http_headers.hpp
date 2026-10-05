// SPDX-License-Identifier: Apache-2.0
#pragma once

#include <string_view>

namespace registry {

enum class BaanderHeader {
    Enrollment,
};

constexpr std::string_view header_name(BaanderHeader header) noexcept {
    return header == BaanderHeader::Enrollment ? "X-Baander-Enrollment" : "";
}

} // namespace registry
