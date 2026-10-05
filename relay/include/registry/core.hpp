// SPDX-License-Identifier: Apache-2.0
#pragma once
#include <cstdint>
#include <nlohmann/json.hpp>
#include <stdexcept>
#include <string>

namespace registry {
using Json = nlohmann::json;
struct Failure : std::runtime_error {
    unsigned status;
    Failure(unsigned status, const char *message) : std::runtime_error(message), status(status) {}
};
struct DatabaseRequest {
    std::string target;
    Json statements;
};
// The raw credential is consumed during validation; it is never stored here.
struct Registration {
    std::string public_id;
    std::string url;
    std::string name;
    std::string version;
    std::string credential_digest;
};
Registration validate_registration(const Json &body);
void validate_public_id(const std::string &public_id);
DatabaseRequest schema_request();
void validate_schema_result(unsigned http_status, const Json &response);
bool validate_enrollment(const Registration &registration, const std::string &token,
                         const std::string &key, std::int64_t now_ms);
DatabaseRequest register_request(const Registration &registration, std::int64_t now_ms,
                                 bool enrollment_allowed = false);
Json register_result(const Registration &registration, unsigned http_status, const Json &response);
DatabaseRequest lookup_request(const std::string &public_id);
Json lookup_result(unsigned http_status, const Json &response, std::int64_t now_ms);
void validate_database_result(unsigned http_status, const Json &response,
                              std::size_t statement_count);
} // namespace registry
