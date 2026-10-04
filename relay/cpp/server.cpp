// SPDX-License-Identifier: Apache-2.0
#include "registry/transport.hpp"
#include <boost/asio.hpp>
#include <boost/asio/ssl.hpp>
#include <boost/beast.hpp>
#include <boost/beast/ssl.hpp>
#include <csignal>
#include <fstream>
#include <iostream>
#include <memory>
#include <set>

namespace registry {
namespace asio = boost::asio;
namespace beast = boost::beast;
namespace http = beast::http;
namespace ssl = asio::ssl;
using tcp = asio::ip::tcp;

struct Server {
    struct Session;
    asio::io_context &context;
    ssl::context tls{ssl::context::tls_server};
    tcp::acceptor acceptor;
    DatabasePool database;
    std::set<std::shared_ptr<Session>> sessions;
    Json schema_snapshot;
    bool initialized = false;
    bool schema_bootstrapped = false;
    asio::steady_timer shutdown_timer;
    const std::chrono::milliseconds shutdown_grace;
    bool stopping = false;
    std::size_t maximum_sessions;
    double tokens;
    const double rate;
    const double burst;
    std::chrono::steady_clock::time_point last_token = std::chrono::steady_clock::now();
    const std::chrono::milliseconds deadline;

    Server(asio::io_context &context, const Json &config, DatabaseConfig database_config)
        : context(context), acceptor(context), database(context, std::move(database_config)),
          shutdown_timer(context), shutdown_grace(config.value("shutdownGraceMs", 5000)),
          maximum_sessions(config.value("maximumSessions", 64u)),
          tokens(config.value("burst", 200.0)), rate(config.value("requestsPerSecond", 200.0)),
          burst(config.value("burst", 200.0)), deadline(config.value("deadlineMs", 2000)) {
        if (maximum_sessions < 1 || maximum_sessions > 256 || rate < 1 || rate > 10000 ||
            burst < 1 || burst > 10000 || deadline.count() < 50 || deadline.count() > 10000 ||
            shutdown_grace.count() < 50 || shutdown_grace.count() > 10000)
            throw std::invalid_argument("Invalid bounded API configuration.");
        tls.set_options(ssl::context::default_workarounds | ssl::context::no_sslv2 |
                        ssl::context::no_sslv3 | ssl::context::no_tlsv1 | ssl::context::no_tlsv1_1);
        tls.use_certificate_chain_file(config.at("certificate"));
        tls.use_private_key_file(config.at("key"), ssl::context::pem);
        if (SSL_CTX_check_private_key(tls.native_handle()) != 1)
            throw std::invalid_argument("API certificate does not match key.");
        const auto port = config.value("port", 9502u);
        if (port < 1 || port > 65535)
            throw std::invalid_argument("Invalid API port.");
        tcp::endpoint endpoint(asio::ip::make_address(config.value("address", "127.0.0.1")),
                               static_cast<unsigned short>(port));
        acceptor.open(endpoint.protocol());
        acceptor.set_option(tcp::acceptor::reuse_address(true));
        acceptor.bind(endpoint);
        acceptor.listen(128);
    }
    bool admit() {
        const auto now = std::chrono::steady_clock::now();
        tokens = std::min(burst,
                          tokens + std::chrono::duration<double>(now - last_token).count() * rate);
        last_token = now;
        if (tokens < 1)
            return false;
        tokens -= 1;
        return true;
    }
    static std::int64_t now_ms() {
        return std::chrono::duration_cast<std::chrono::milliseconds>(
                   std::chrono::system_clock::now().time_since_epoch())
            .count();
    }
    asio::awaitable<Json> route(const http::request<http::string_body> &request) {
        const std::string target(request.target());
        if (request.method() == http::verb::get && target == "/health")
            co_return Json{{"data", {{"alive", true}}}};
        if (!admit())
            throw Failure(429, "Request rate exceeded.");
        if (request.method() == http::verb::get && target == "/ready") {
            if (!schema_bootstrapped)
                throw Failure(503, "Registry schema is not ready.");
            try {
                auto result = co_await database.request(
                    {"/db/query?level=linearizable&associative",
                     Json::array(
                         {"SELECT version, checksum FROM schema_migrations ORDER BY version",
                          "SELECT public_id, credential_digest, url, name, version, created_ms, "
                          "updated_ms, last_seen_ms, revision FROM registries WHERE 0"})});
                validate_database_result(result.status, result.body, 2);
                const auto &schema = result.body["results"][0];
                if (!schema.contains("types") || !schema.contains("rows") ||
                    schema["types"] != schema_snapshot["types"] ||
                    schema["rows"] != schema_snapshot["rows"])
                    throw Failure(503, "Registry schema is incompatible.");
                const Json expected_columns{
                    {"public_id", "text"},     {"credential_digest", "text"},
                    {"url", "text"},           {"name", "text"},
                    {"version", "text"},       {"created_ms", "integer"},
                    {"updated_ms", "integer"}, {"last_seen_ms", "integer"},
                    {"revision", "integer"}};
                const auto &registry_table = result.body["results"][1];
                if (!registry_table.contains("types") ||
                    registry_table["types"] != expected_columns)
                    throw Failure(503, "Registry table is incompatible.");
                initialized = true;
                co_return Json{{"data", {{"ready", true}}}};
            } catch (const std::exception &) {
                initialized = false;
                throw;
            }
        }
        if (!initialized)
            throw Failure(503, "Registry schema is not ready.");
        if (request.method() == http::verb::post && target == "/api/servers/register") {
            const auto registration =
                validate_registration(parse_json_body(request.body(), 8192, 8));
            auto result = co_await database.request(register_request(registration, now_ms()));
            co_return register_result(registration, result.status, result.body);
        }
        const std::string prefix = "/api/servers/";
        if (request.method() == http::verb::get && target.starts_with(prefix)) {
            auto result = co_await database.request(lookup_request(target.substr(prefix.size())));
            co_return lookup_result(result.status, result.body, now_ms());
        }
        throw Failure(404, "Route not found.");
    }
    asio::awaitable<void> initialize() {
        // Retry initialization with a bounded delay; readiness itself never writes schema.
        while (!stopping && !initialized) {
            try {
                auto result = co_await database.request(schema_request());
                validate_schema_result(result.status, result.body);
                schema_snapshot = result.body["results"][3];
                initialized = true;
                schema_bootstrapped = true;
            } catch (const std::exception &) {
                initialized = false;
            }
            if (!initialized && !stopping) {
                asio::steady_timer retry(context, std::chrono::seconds(1));
                co_await retry.async_wait(asio::use_awaitable);
            }
        }
        co_return;
    }
    asio::awaitable<void> listen();
    void stop();
};
struct Server::Session : std::enable_shared_from_this<Session> {
    Server &server;
    beast::ssl_stream<beast::tcp_stream> stream;
    asio::steady_timer deadline_timer;
    asio::cancellation_signal cancellation;
    bool active_request = false;
    Session(Server &server, tcp::socket socket)
        : server(server), stream(std::move(socket), server.tls), deadline_timer(server.context) {}
    void close() {
        boost::system::error_code ignored;
        beast::get_lowest_layer(stream).socket().cancel(ignored);
        beast::get_lowest_layer(stream).socket().close(ignored);
    }
    void arm_deadline() {
        deadline_timer.expires_after(server.deadline);
        deadline_timer.async_wait(
            [weak = weak_from_this()](const boost::system::error_code &error) {
                if (!error) {
                    if (auto session = weak.lock()) {
                        session->close();
                        session->cancellation.emit(asio::cancellation_type::terminal);
                    }
                }
            });
    }
    asio::awaitable<void> run() {
        try {
            beast::get_lowest_layer(stream).expires_after(server.deadline);
            co_await stream.async_handshake(ssl::stream_base::server, asio::use_awaitable);
            beast::flat_buffer buffer;
            http::request_parser<http::string_body> parser;
            parser.header_limit(8192);
            parser.body_limit(8192);
            boost::system::error_code error;
            co_await http::async_read(stream, buffer, parser,
                                      asio::redirect_error(asio::use_awaitable, error));
            unsigned status = 200;
            Json body;
            if (error) {
                status = error == http::error::body_limit ? 413 : 400;
                body = {{"error", "Invalid or oversized HTTP request."}};
            } else {
                try {
                    if (server.stopping)
                        throw Failure(503, "Registry is shutting down.");
                    active_request = true;
                    body = co_await server.route(parser.release());
                } catch (const Failure &failure) {
                    status = failure.status;
                    body = {{"error", failure.what()}};
                } catch (const std::exception &) {
                    status = 503;
                    body = {{"error", "Authoritative registry unavailable."}};
                }
            }
            http::response<http::string_body> response{static_cast<http::status>(status), 11};
            response.set(http::field::content_type, "application/json");
            response.set(http::field::cache_control, "no-store");
            if (status == 503 || status == 429)
                response.set(http::field::retry_after, "1");
            response.keep_alive(false);
            response.body() = body.dump();
            response.prepare_payload();
            // The session timer also bounds the complete handshake/read/database/write lifecycle.
            beast::get_lowest_layer(stream).expires_after(server.deadline);
            co_await http::async_write(stream, response, asio::use_awaitable);
        } catch (const std::exception &) {
            // Transport disconnect/deadline errors never include credentials or request bodies in
            // logs.
        }
        deadline_timer.cancel();
        close();
        server.sessions.erase(shared_from_this());
        if (server.stopping && server.sessions.empty()) {
            server.shutdown_timer.cancel();
            server.database.stop();
        }
    }
};
asio::awaitable<void> Server::listen() {
    while (!stopping) {
        boost::system::error_code error;
        auto socket =
            co_await acceptor.async_accept(asio::redirect_error(asio::use_awaitable, error));
        if (error) {
            if (stopping)
                break;
            continue;
        }
        if (stopping) {
            socket.close(error);
            break;
        }
        if (sessions.size() >= maximum_sessions) {
            socket.close(error);
            continue; // No unbounded rejection tasks or queues.
        }
        auto session = std::make_shared<Session>(*this, std::move(socket));
        sessions.insert(session);
        session->arm_deadline();
        asio::co_spawn(context, session->run(),
                       asio::bind_cancellation_slot(session->cancellation.slot(),
                                                    [session](std::exception_ptr) {}));
    }
}
void Server::stop() {
    if (stopping)
        return;
    stopping = true;
    boost::system::error_code ignored;
    acceptor.close(ignored);
    for (const auto &session : sessions) {
        if (!session->active_request) {
            session->close();
            session->cancellation.emit(asio::cancellation_type::terminal);
        }
    }
    if (sessions.empty()) {
        database.stop();
        return;
    }
    shutdown_timer.expires_after(shutdown_grace);
    shutdown_timer.async_wait([this](const boost::system::error_code &error) {
        if (!error) {
            database.stop();
            for (const auto &session : sessions) {
                session->close();
                session->cancellation.emit(asio::cancellation_type::terminal);
            }
        }
    });
}
} // namespace registry
int main(int argc, char **argv) {
    if (argc != 3 || std::string(argv[1]) != "--config") {
        std::cerr << "Usage: baander-registry --config PATH\n";
        return 2;
    }
    try {
        std::ifstream file(argv[2], std::ios::binary);
        std::string input(65537, '\0');
        file.read(input.data(), input.size());
        input.resize(static_cast<std::size_t>(file.gcount()));
        if (!file.is_open())
            throw std::invalid_argument("Missing configuration.");
        const auto config = registry::parse_json_body(input, 65536, 8);
        const auto &database = config.at("database");
        registry::DatabaseConfig db;
        for (const auto &endpoint : database.at("endpoints"))
            db.endpoints.push_back({endpoint.at("url"), endpoint.value("connectAddress", "")});
        db.ca_file = database.at("ca");
        db.client_certificate = database.at("certificate");
        db.client_key = database.at("key");
        db.username = database.at("username");
        db.password_file = database.at("passwordFile");
        db.connections = database.value("connections", 8u);
        db.deadline = std::chrono::milliseconds(database.value("deadlineMs", 2000));
        boost::asio::io_context context(1);
        registry::Server server(context, config.at("api"), std::move(db));
        boost::asio::signal_set signals(context, SIGINT, SIGTERM);
        signals.async_wait([&server](const boost::system::error_code &error, int) {
            if (!error)
                server.stop();
        });
        boost::asio::co_spawn(context, server.initialize(), [](std::exception_ptr) {});
        boost::asio::co_spawn(context, server.listen(), [](std::exception_ptr) {});
        context.run(); // Drain cancellation before pool/session destruction.
    } catch (const std::exception &) {
        std::cerr << "Registry startup configuration or TLS initialization failed.\n";
        return 2;
    }
}
