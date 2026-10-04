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
        database.ca_file = config.at("ca");
        database.client_certificate = config.at("certificate");
        database.client_key = config.at("key");
        database.username = "registry";
        database.password_file = config.at("passwordFile");
        database.deadline = std::chrono::milliseconds(config.value("deadlineMs", 500));
        database.connections = 1;
        registry::DatabasePool pool(context, database);
        const auto count = std::stoi(argv[2]);
        for (int i = 0; i < (count < 0 ? 1 : count); ++i) {
            boost::asio::co_spawn(
                context,
                [&pool, &context, count]() -> boost::asio::awaitable<void> {
                    for (int attempt = 0; attempt < (count < 0 ? -count : 1); ++attempt) {
                        try {
                            const auto response =
                                co_await pool.request({"/db/query?level=linearizable&associative",
                                                       registry::Json::array({"SELECT 1"})});
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
