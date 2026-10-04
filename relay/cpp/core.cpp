// SPDX-License-Identifier: Apache-2.0
#include "registry/core.hpp"
#include <algorithm>
#include <array>
#include <boost/url.hpp>
#include <openssl/evp.h>

namespace registry {
namespace {
std::string digest(const std::string &value) {
    std::array<unsigned char, EVP_MAX_MD_SIZE> bytes{};
    unsigned length = 0;
    if (EVP_Digest(value.data(), value.size(), bytes.data(), &length, EVP_sha256(), nullptr) != 1 ||
        length != 32)
        throw Failure(503, "Credential hashing unavailable.");
    static constexpr char hex[] = "0123456789abcdef";
    std::string result;
    result.reserve(64);
    for (unsigned i = 0; i < length; ++i) {
        result += hex[bytes[i] >> 4];
        result += hex[bytes[i] & 15];
    }
    return result;
}
bool hex_credential(const std::string &value) {
    return value.size() == 64 && std::all_of(value.begin(), value.end(), [](char c) {
               return (c >= '0' && c <= '9') || (c >= 'a' && c <= 'f');
           });
}
std::string field(const Json &body, const char *key, std::size_t maximum, bool characters = false) {
    if (!body.contains(key) || !body[key].is_string())
        throw Failure(422, "Invalid registration fields.");
    auto value = body[key].get<std::string>();
    std::size_t length = value.size();
    if (characters) {
        // Delegate UTF-8 validation to nlohmann; count scalar starts, not bytes.
        try {
            [[maybe_unused]] const auto validated = Json(value).dump();
        } catch (const Json::exception &) {
            throw Failure(422, "Invalid registration fields.");
        }
        length = static_cast<std::size_t>(std::count_if(
            value.begin(), value.end(), [](unsigned char c) { return (c & 0xc0) != 0x80; }));
    }
    if (value.empty() || length > maximum ||
        std::any_of(value.begin(), value.end(), [](unsigned char c) { return c < 32 || c == 127; }))
        throw Failure(422, "Invalid registration fields.");
    return value;
}
const Json &rows(const Json &result,
                 std::initializer_list<std::pair<const char *, const char *>> columns) {
    if (!result.contains("types") || !result["types"].is_object())
        throw Failure(503, "Registry query result unavailable.");
    for (const auto &[column, type] : columns)
        if (!result["types"].contains(column) || result["types"][column] != type)
            throw Failure(503, "Registry query result unavailable.");
    static const Json empty = Json::array();
    if (!result.contains("rows"))
        return empty;
    if (!result["rows"].is_array())
        throw Failure(503, "Registry result unavailable.");
    return result["rows"];
}

} // namespace

void validate_public_id(const std::string &value) {
    if ((value.empty() || value.size() > 128) ||
        !std::all_of(value.begin(), value.end(), [](char c) {
            return (c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z') || (c >= '0' && c <= '9') ||
                   c == '_' || c == '-';
        }))
        throw Failure(422, "Invalid public identity.");
}
Registration validate_registration(const Json &body) {
    if (!body.is_object())
        throw Failure(422, "Registration must be an object.");
    Registration r;
    r.public_id = field(body, "publicId", 128);
    validate_public_id(r.public_id);
    r.url = field(body, "url", 2048);
    auto parsed = boost::urls::parse_uri(r.url);
    if (!parsed || parsed->scheme() != "https" || !parsed->has_authority() ||
        parsed->host().empty() || parsed->host_type() != boost::urls::host_type::name ||
        parsed->encoded_host().find('%') != boost::urls::pct_string_view::npos ||
        parsed->has_userinfo() || parsed->has_fragment())
        throw Failure(
            422, "Registration requires an HTTPS hostname URL without credentials or fragment.");
    r.name = field(body, "name", 128, true);
    r.version = body.contains("version") ? field(body, "version", 64) : "0.0.0";
    const auto credential = field(body, "apiKey", 64);
    if (!hex_credential(credential))
        throw Failure(422, "Credential must be 32 bytes of lowercase hex.");
    r.credential_digest = digest(credential);
    return r;
}

namespace {
constexpr const char *migration_schema = "CREATE TABLE IF NOT EXISTS schema_migrations (version "
                                         "INTEGER PRIMARY KEY NOT NULL, checksum TEXT NOT NULL)";
constexpr const char *registry_schema = R"SQL(CREATE TABLE IF NOT EXISTS registries (
    public_id TEXT PRIMARY KEY NOT NULL,
    credential_digest TEXT NOT NULL,
    url TEXT NOT NULL, name TEXT NOT NULL, version TEXT NOT NULL,
    created_ms INTEGER NOT NULL, updated_ms INTEGER NOT NULL, last_seen_ms INTEGER NOT NULL,
    revision INTEGER NOT NULL CHECK(revision > 0)
))SQL";
std::string schema_checksum() {
    return digest(std::string(migration_schema) + "\n" + registry_schema);
}
} // namespace
DatabaseRequest schema_request() {
    return {"/db/request?transaction&level=linearizable&associative",
            Json::array({migration_schema, registry_schema,
                         Json::array({"INSERT INTO schema_migrations(version, checksum) VALUES(1, "
                                      "?) ON CONFLICT(version) DO NOTHING",
                                      schema_checksum()}),
                         "SELECT version, checksum FROM schema_migrations ORDER BY version"})};
}
void validate_schema_result(unsigned status, const Json &response) {
    validate_database_result(status, response, 4);
    const auto &records =
        rows(response["results"][3], {{"version", "integer"}, {"checksum", "text"}});
    if (records.size() != 1 || !records[0].is_object() || !records[0].contains("version") ||
        !records[0]["version"].is_number_integer() ||
        records[0]["version"].get<std::int64_t>() != 1 || !records[0].contains("checksum") ||
        records[0]["checksum"] != schema_checksum()) {
        throw Failure(503, "Registry schema is incompatible.");
    }
}
DatabaseRequest register_request(const Registration &r, std::int64_t now_ms) {
    if (now_ms < 0)
        throw Failure(503, "Registry clock unavailable.");
    return {
        "/db/request?transaction&level=linearizable&associative",
        Json::array({Json::array({R"SQL(INSERT INTO registries
          (public_id, credential_digest, url, name, version, created_ms, updated_ms, last_seen_ms, revision)
          VALUES (:public_id, :credential_digest, :url, :name, :version, :now_ms, :now_ms, :now_ms, 1)
          ON CONFLICT(public_id) DO UPDATE SET
            url=excluded.url, name=excluded.name, version=excluded.version,
            updated_ms=MAX(registries.updated_ms, excluded.updated_ms),
            last_seen_ms=MAX(registries.last_seen_ms, excluded.last_seen_ms), revision=registries.revision+1
          WHERE registries.credential_digest=excluded.credential_digest)SQL",
                                  Json{{"public_id", r.public_id},
                                       {"credential_digest", r.credential_digest},
                                       {"url", r.url},
                                       {"name", r.name},
                                       {"version", r.version},
                                       {"now_ms", now_ms}}}),
                     Json::array({R"SQL(SELECT revision, updated_ms, last_seen_ms FROM registries
          WHERE public_id=:public_id AND credential_digest=:credential_digest)SQL",
                                  Json{{"public_id", r.public_id},
                                       {"credential_digest", r.credential_digest}}})})};
}
void validate_database_result(unsigned status, const Json &response, std::size_t count) {
    if (status != 200 || !response.is_object() || response.contains("error") ||
        !response.contains("results") || !response["results"].is_array() ||
        response["results"].size() != count)
        throw Failure(503, "Authoritative registry result unavailable.");
    for (const auto &result : response["results"])
        if (!result.is_object() || result.contains("error"))
            throw Failure(503, "Authoritative registry result unavailable.");
}
Json register_result(const Registration &r, unsigned status, const Json &response) {
    validate_database_result(status, response, 2);
    const auto &result_rows =
        rows(response["results"][1],
             {{"revision", "integer"}, {"updated_ms", "integer"}, {"last_seen_ms", "integer"}});
    if (result_rows.empty())
        throw Failure(403, "Public identity belongs to another credential.");
    if (result_rows.size() != 1)
        throw Failure(503, "Registry result unavailable.");
    const auto &row = result_rows[0];
    if (!row.is_object() || !row.contains("revision") || !row["revision"].is_number_integer() ||
        row["revision"].get<std::int64_t>() < 1 || !row.contains("updated_ms") ||
        !row["updated_ms"].is_number_integer() || row["updated_ms"].get<std::int64_t>() < 0 ||
        !row.contains("last_seen_ms") || !row["last_seen_ms"].is_number_integer() ||
        row["last_seen_ms"].get<std::int64_t>() < 0)
        throw Failure(503, "Registry result unavailable.");
    return Json{{"data",
                 {{"registered", true},
                  {"publicId", r.public_id},
                  {"revision", row["revision"]},
                  {"updatedAtMs", row["updated_ms"]},
                  {"lastHeartbeatMs", row["last_seen_ms"]}}}};
}
DatabaseRequest lookup_request(const std::string &public_id) {
    validate_public_id(public_id);
    return {"/db/query?level=linearizable&associative",
            Json::array({Json::array({"SELECT public_id, url, name, version, updated_ms, "
                                      "last_seen_ms, revision FROM registries WHERE public_id=?",
                                      public_id})})};
}
Json lookup_result(unsigned status, const Json &response, std::int64_t now_ms) {
    validate_database_result(status, response, 1);
    const auto &result_rows = rows(response["results"][0], {{"public_id", "text"},
                                                            {"url", "text"},
                                                            {"name", "text"},
                                                            {"version", "text"},
                                                            {"updated_ms", "integer"},
                                                            {"last_seen_ms", "integer"},
                                                            {"revision", "integer"}});
    if (result_rows.empty())
        throw Failure(404, "Server not found.");
    if (result_rows.size() != 1 || now_ms < 0)
        throw Failure(503, "Registry result unavailable.");
    const auto &r = result_rows[0];
    for (const auto &key : {"public_id", "url", "name", "version"})
        if (!r.contains(key) || !r[key].is_string())
            throw Failure(503, "Registry result unavailable.");
    if (!r.contains("updated_ms") || !r["updated_ms"].is_number_integer() ||
        r["updated_ms"].get<std::int64_t>() < 0 || !r.contains("last_seen_ms") ||
        !r["last_seen_ms"].is_number_integer() || !r.contains("revision") ||
        !r["revision"].is_number_integer())
        throw Failure(503, "Registry result unavailable.");
    const auto last_seen = r["last_seen_ms"].get<std::int64_t>();
    if (last_seen < 0 || r["revision"].get<std::int64_t>() < 1)
        throw Failure(503, "Registry result unavailable.");
    if (now_ms > last_seen && now_ms - last_seen >= 600000)
        throw Failure(404, "Server not found or offline.");
    return Json{{"data",
                 {{"publicId", r["public_id"]},
                  {"url", r["url"]},
                  {"name", r["name"]},
                  {"version", r["version"]},
                  {"updatedAtMs", r["updated_ms"]},
                  {"lastHeartbeatMs", last_seen},
                  {"revision", r["revision"]}}}};
}
} // namespace registry
