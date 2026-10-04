// SPDX-License-Identifier: Apache-2.0
#pragma once

#include "registry/core.hpp"
#include <boost/asio/awaitable.hpp>
#include <boost/asio/io_context.hpp>
#include <chrono>
#include <memory>
#include <string>
#include <vector>

namespace registry {
struct EndpointConfig {
    std::string url;
    // Optional operator-controlled static address; TLS still verifies URL hostname.
    std::string connect_address;
};
struct DatabaseConfig {
    std::vector<EndpointConfig> endpoints;
    std::string ca_file;
    std::string client_certificate;
    std::string client_key;
    std::string username;
    std::string password_file;
    std::size_t connections = 8;
    std::chrono::milliseconds deadline{2000};
};
struct DatabaseResponse {
    unsigned status;
    Json body;
};
Json parse_json_body(const std::string &body, std::size_t maximum_bytes, unsigned maximum_depth);
class DatabasePool {
  public:
    DatabasePool(boost::asio::io_context &context, DatabaseConfig config);
    ~DatabasePool();
    DatabasePool(const DatabasePool &) = delete;
    DatabasePool &operator=(const DatabasePool &) = delete;
    boost::asio::awaitable<DatabaseResponse> request(DatabaseRequest request);
    void stop();

  private:
    struct Impl;
    std::unique_ptr<Impl> impl_;
};
} // namespace registry
