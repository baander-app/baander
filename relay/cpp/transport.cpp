// SPDX-License-Identifier: Apache-2.0
#include "registry/transport.hpp"
#include <algorithm>
#include <array>
#include <boost/asio.hpp>
#include <boost/asio/experimental/awaitable_operators.hpp>
#include <boost/asio/ssl.hpp>
#include <boost/beast.hpp>
#include <boost/beast/ssl.hpp>
#include <boost/url.hpp>
#include <charconv>
#include <fstream>
#include <limits>
#include <openssl/crypto.h>
#include <openssl/evp.h>
#include <openssl/ssl.h>
#include <optional>

namespace registry {
namespace asio = boost::asio;
namespace beast = boost::beast;
namespace http = beast::http;
namespace ssl = asio::ssl;
using tcp = asio::ip::tcp;
using namespace asio::experimental::awaitable_operators;

Json parse_json_body(const std::string &body, std::size_t maximum_bytes, unsigned maximum_depth) {
    if (body.size() > maximum_bytes) {
        throw Failure(413, "JSON body is too large.");
    }
    try {
        return Json::parse(body, [maximum_depth](int depth, Json::parse_event_t event, Json &) {
            if ((event == Json::parse_event_t::object_start ||
                 event == Json::parse_event_t::array_start) &&
                static_cast<unsigned>(depth) >= maximum_depth) {
                throw Failure(400, "JSON nesting limit exceeded.");
            }
            return true;
        });
    } catch (const Json::exception &) {
        throw Failure(400, "Malformed JSON body.");
    }
}
namespace {
std::string authorization(const DatabaseConfig &config) {
    if (config.username.empty() || config.username.size() > 128 ||
        config.username.find(':') != std::string::npos ||
        config.username.find_first_of("\r\n") != std::string::npos ||
        config.password_file.empty()) {
        throw std::invalid_argument("Database authentication is required.");
    }
    std::ifstream file(config.password_file, std::ios::binary);
    std::array<char, 258> bytes{};
    file.read(bytes.data(), bytes.size());
    const auto size = file.gcount();
    if (!file.is_open() || size < 1 || size > 256 || !file.eof()) {
        OPENSSL_cleanse(bytes.data(), bytes.size());
        throw std::invalid_argument("Database password file must contain 1–256 bytes.");
    }
    std::string password(bytes.data(), static_cast<std::size_t>(size));
    OPENSSL_cleanse(bytes.data(), bytes.size());
    if (!password.empty() && password.back() == '\n')
        password.pop_back();
    if (!password.empty() && password.back() == '\r')
        password.pop_back();
    if (password.empty() || password.find_first_of("\r\n") != std::string::npos) {
        OPENSSL_cleanse(password.data(), password.size());
        throw std::invalid_argument("Database password file is invalid.");
    }
    std::string input = config.username + ':' + password;
    OPENSSL_cleanse(password.data(), password.size());
    std::string encoded(4 * ((input.size() + 2) / 3), '\0');
    const int length = EVP_EncodeBlock(reinterpret_cast<unsigned char *>(encoded.data()),
                                       reinterpret_cast<const unsigned char *>(input.data()),
                                       static_cast<int>(input.size()));
    OPENSSL_cleanse(input.data(), input.size());
    if (length < 0 || static_cast<std::size_t>(length) != encoded.size()) {
        throw std::runtime_error("Database authentication encoding failed.");
    }
    return "Basic " + encoded;
}
struct Endpoint {
    std::string hostname;
    std::string port;
    std::string authority;
    std::string connect_address;
};
Endpoint endpoint(const EndpointConfig &config) {
    auto parsed = boost::urls::parse_uri(config.url);
    if (!parsed || parsed->scheme() != "https" ||
        parsed->host_type() != boost::urls::host_type::name || parsed->host().empty() ||
        parsed->has_userinfo() || parsed->has_fragment() || parsed->has_query() ||
        (parsed->encoded_path() != "" && parsed->encoded_path() != "/") ||
        parsed->encoded_host().find('%') != boost::urls::pct_string_view::npos) {
        throw std::invalid_argument("Database endpoint must be an HTTPS hostname origin.");
    }
    if (!config.connect_address.empty()) {
        boost::system::error_code error;
        asio::ip::make_address(config.connect_address, error);
        if (error)
            throw std::invalid_argument("Database static address must be an IP address.");
    }
    const auto port = parsed->has_port() ? std::string(parsed->port()) : "443";
    unsigned number = 0;
    const auto converted = std::from_chars(port.data(), port.data() + port.size(), number);
    if (converted.ec != std::errc{} || converted.ptr != port.data() + port.size() || number == 0 ||
        number > 65535) {
        throw std::invalid_argument("Database endpoint port is invalid.");
    }
    return {std::string(parsed->host()), port, std::string(parsed->authority().buffer()),
            config.connect_address};
}
} // namespace
struct DatabasePool::Impl {
    struct Slot {
        explicit Slot(asio::io_context &context) : resolver(context) {}
        tcp::resolver resolver;
        std::unique_ptr<beast::ssl_stream<beast::tcp_stream>> stream;
        std::optional<std::size_t> endpoint_index;
        bool busy = false;
        void close() {
            resolver.cancel();
            if (stream) {
                boost::system::error_code ignored;
                beast::get_lowest_layer(*stream).socket().cancel(ignored);
                beast::get_lowest_layer(*stream).socket().close(ignored);
            }
            endpoint_index.reset();
        }
    };
    asio::io_context &context;
    DatabaseConfig config;
    ssl::context tls{ssl::context::tls_client};
    std::vector<Endpoint> endpoints;
    std::vector<std::unique_ptr<Slot>> slots;
    std::string auth;
    std::size_t next_endpoint = 0;
    bool stopping = false;

    Impl(asio::io_context &context, DatabaseConfig config)
        : context(context), config(std::move(config)) {
        if (this->config.connections < 1 || this->config.connections > 16 ||
            this->config.endpoints.empty() || this->config.endpoints.size() > 5 ||
            this->config.deadline.count() < 50 || this->config.deadline.count() > 10000 ||
            this->config.ca_file.empty() || this->config.client_certificate.empty() ||
            this->config.client_key.empty()) {
            throw std::invalid_argument("Invalid bounded database pool configuration.");
        }
        tls.set_options(ssl::context::default_workarounds | ssl::context::no_sslv2 |
                        ssl::context::no_sslv3 | ssl::context::no_tlsv1 | ssl::context::no_tlsv1_1);
        tls.set_verify_mode(ssl::verify_peer);
        tls.load_verify_file(this->config.ca_file);
        tls.use_certificate_chain_file(this->config.client_certificate);
        tls.use_private_key_file(this->config.client_key, ssl::context::pem);
        if (SSL_CTX_check_private_key(tls.native_handle()) != 1)
            throw std::invalid_argument("Database client certificate does not match key.");
        auth = authorization(this->config);
        for (const auto &value : this->config.endpoints)
            endpoints.push_back(endpoint(value));
        for (std::size_t i = 0; i < this->config.connections; ++i)
            slots.push_back(std::make_unique<Slot>(context));
    }
    ~Impl() { OPENSSL_cleanse(auth.data(), auth.size()); }

    asio::awaitable<DatabaseResponse> exchange(Slot &slot, const DatabaseRequest &request,
                                               std::chrono::steady_clock::time_point deadline) {
        try {
            if (!slot.endpoint_index) {
                const auto index = next_endpoint++ % endpoints.size();
                const auto &target = endpoints[index];
                slot.stream = std::make_unique<beast::ssl_stream<beast::tcp_stream>>(context, tls);
                slot.stream->set_verify_callback(ssl::host_name_verification(target.hostname));
                if (SSL_set_tlsext_host_name(slot.stream->native_handle(),
                                             target.hostname.c_str()) != 1) {
                    throw Failure(503, "Database TLS initialization failed.");
                }
                beast::get_lowest_layer(*slot.stream).expires_at(deadline);
                if (!target.connect_address.empty()) {
                    co_await beast::get_lowest_layer(*slot.stream)
                        .async_connect(
                            tcp::endpoint(asio::ip::make_address(target.connect_address),
                                          static_cast<unsigned short>(std::stoul(target.port))),
                            asio::use_awaitable);
                } else {
                    auto addresses = co_await slot.resolver.async_resolve(
                        target.hostname, target.port, asio::use_awaitable);
                    co_await beast::get_lowest_layer(*slot.stream)
                        .async_connect(addresses, asio::use_awaitable);
                }
                co_await slot.stream->async_handshake(ssl::stream_base::client,
                                                      asio::use_awaitable);
                slot.endpoint_index = index;
            }
            beast::get_lowest_layer(*slot.stream).expires_at(deadline);
            http::request<http::string_body> outgoing{http::verb::post, request.target, 11};
            outgoing.set(http::field::host, endpoints[*slot.endpoint_index].authority);
            outgoing.set(http::field::content_type, "application/json");
            outgoing.set(http::field::authorization, auth);
            outgoing.keep_alive(true);
            outgoing.body() = request.statements.dump();
            outgoing.prepare_payload();
            co_await http::async_write(*slot.stream, outgoing, asio::use_awaitable);
            beast::flat_buffer buffer;
            http::response_parser<http::string_body> parser;
            parser.header_limit(8192);
            parser.body_limit(65536);
            co_await http::async_read(*slot.stream, buffer, parser, asio::use_awaitable);
            auto response = parser.release();
            auto body = parse_json_body(response.body(), 65536, 8);
            if (!response.keep_alive() || response.result_int() != 200)
                slot.close();
            co_return DatabaseResponse{response.result_int(), std::move(body)};
        } catch (const std::exception &) {
            slot.close();
            co_return DatabaseResponse{0, Json::object()};
        }
    }
};
DatabasePool::DatabasePool(asio::io_context &context, DatabaseConfig config)
    : impl_(std::make_unique<Impl>(context, std::move(config))) {}
DatabasePool::~DatabasePool() = default;
void DatabasePool::stop() {
    impl_->stopping = true;
    for (auto &slot : impl_->slots)
        slot->close();
}
asio::awaitable<DatabaseResponse> DatabasePool::request(DatabaseRequest request) {
    if (impl_->stopping)
        throw Failure(503, "Registry is shutting down.");
    auto found = std::find_if(impl_->slots.begin(), impl_->slots.end(),
                              [](const auto &slot) { return !slot->busy; });
    if (found == impl_->slots.end())
        throw Failure(503, "Database request capacity exhausted.");
    auto &slot = **found;
    slot.busy = true;
    struct Release {
        Impl::Slot &slot;
        ~Release() { slot.busy = false; }
    } release{slot};
    const auto deadline = std::chrono::steady_clock::now() + impl_->config.deadline;
    asio::steady_timer timer(impl_->context, deadline);
    auto result = co_await (impl_->exchange(slot, request, deadline) ||
                            timer.async_wait(asio::use_awaitable));
    if (result.index() != 0) {
        slot.close();
        throw Failure(503, "Database request deadline exceeded.");
    }
    auto response = std::get<0>(std::move(result));
    if (response.status != 200)
        throw Failure(503, "Authoritative database result unavailable.");
    co_return response;
}
} // namespace registry
