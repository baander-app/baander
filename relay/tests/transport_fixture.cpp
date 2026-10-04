// SPDX-License-Identifier: Apache-2.0
#include "registry/transport.hpp"
#include <boost/asio.hpp>
#include <fstream>
#include <iostream>

int main(int argc, char **argv) {
    if (argc != 3)
        return 2;
    try {
        std::ifstream input(argv[1]);
        const auto config = registry::Json::parse(input);
        boost::asio::io_context context(1);
        registry::DatabaseConfig database;
        database.endpoints = {{config.at("url"), "127.0.0.1"}};
        for (const auto &url : config.value("additionalUrls", registry::Json::array()))
            database.endpoints.push_back({url, "127.0.0.1"});
        database.ca_file = config.at("ca");
        database.client_certificate = config.at("certificate");
        database.client_key = config.at("key");
        database.username = "registry";
        database.password_file = config.at("passwordFile");
        database.deadline = std::chrono::milliseconds(config.value("deadlineMs", 500));
        database.connections = 1;
        registry::DatabasePool pool(context, database);
        const auto count = std::stoi(argv[2]);
        const auto rotation = config.value("rotateOnError", false);
        for (int i = 0; i < (count < 0 ? 1 : count); ++i) {
            boost::asio::co_spawn(
                context,
                [&pool, &context, count, rotation]() -> boost::asio::awaitable<void> {
                    for (int attempt = 0; attempt < (count < 0 ? -count : 1); ++attempt) {
                        try {
                            registry::DatabaseRequest request{
                                "/db/query?level=linearizable&associative",
                                registry::Json::array({"SELECT 1"})};
                            registry::Registration registration;
                            if (rotation) {
                                registration = registry::validate_registration(
                                    {{"publicId", "rotation-fixture"},
                                     {"url", "https://rotation.baander.app"},
                                     {"name", "Rotation fixture"},
                                     {"version", "1.0.0"},
                                     {"apiKey", std::string(64, 'a')}});
                                request = attempt == 0
                                              ? registry::register_request(registration, 2000)
                                              : registry::lookup_request(registration.public_id);
                            }
                            const auto response = co_await pool.request(std::move(request));
                            if (rotation) {
                                [[maybe_unused]] const auto decoded =
                                    attempt == 0 ? registry::register_result(
                                                       registration, response.status, response.body)
                                                 : registry::lookup_result(response.status,
                                                                           response.body, 3000);
                            } else {
                                registry::validate_database_result(response.status, response.body,
                                                                   1);
                            }
                            std::cout << response.status << '\n';
                        } catch (const registry::Failure &failure) {
                            std::cout << failure.status << '\n';
                        }
                        if (count < 0) {
                            boost::asio::steady_timer delay(context,
                                                            std::chrono::milliseconds(300));
                            co_await delay.async_wait(boost::asio::use_awaitable);
                        }
                    }
                    co_return;
                },
                [](std::exception_ptr error) {
                    if (error)
                        std::cout << "fixture-error\n";
                });
        }
        context.run();
        return 0;
    } catch (const std::exception &) {
        std::cerr << "Invalid transport fixture configuration.\n";
        return 2;
    }
}
