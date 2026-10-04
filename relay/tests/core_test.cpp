// SPDX-License-Identifier: Apache-2.0
#include "registry/core.hpp"
#include <gtest/gtest.h>
using namespace registry;
namespace {
Json body() {
    return {{"publicId", "Baander_server_0000001"},
            {"url", "https://server.baander.app/base"},
            {"name", "Baander"},
            {"version", "1.0.0"},
            {"apiKey", std::string(64, 'a')}};
}
template <class F> void failure(unsigned status, F operation) {
    try {
        operation();
        FAIL() << "Expected safe failure";
    } catch (const Failure &error) {
        EXPECT_EQ(status, error.status);
        EXPECT_EQ(std::string::npos, std::string(error.what()).find(std::string(64, 'a')));
    }
}
Json lookup_types() {
    return {{"public_id", "text"},  {"url", "text"},           {"name", "text"},
            {"version", "text"},    {"updated_ms", "integer"}, {"last_seen_ms", "integer"},
            {"revision", "integer"}};
}
Json committed_registration() {
    return {
        {"results", Json::array({Json{{"rows_affected", 1}},
                                 Json{{"types", Json{{"revision", "integer"},
                                                     {"updated_ms", "integer"},
                                                     {"last_seen_ms", "integer"}}},
                                      {"rows", Json::array({Json{{"revision", 1},
                                                                 {"updated_ms", 1000},
                                                                 {"last_seen_ms", 1000}}})}}})}};
}
} // namespace
TEST(Registration, StoresOnlyDigestsAndParameterizedValues) {
    const auto r = validate_registration(body());
    EXPECT_EQ(64, r.credential_digest.size());
    EXPECT_NE(body()["apiKey"].get<std::string>(), r.credential_digest);
    const auto request = register_request(r, 1000);
    EXPECT_EQ("/db/request?transaction&level=linearizable&associative", request.target);
    EXPECT_EQ(2, request.statements.size());
    EXPECT_EQ(std::string::npos,
              request.statements.dump().find(body()["apiKey"].get<std::string>()));
    EXPECT_EQ(r.public_id, request.statements[0][1]["public_id"].get<std::string>());
    EXPECT_EQ(std::string::npos, request.statements[0][0].get<std::string>().find(r.public_id));
    EXPECT_EQ(1, register_result(r, 200, committed_registration())["data"]["revision"].get<int>());
}
TEST(Registration, RequiresClientGenerated256BitCredential) {
    for (const auto &value : {std::string(63, 'a'), std::string(64, 'x'), std::string(64, 'A')}) {
        auto b = body();
        b["apiKey"] = value;
        failure(422, [&] { validate_registration(b); });
    }
}
TEST(Registration, RejectsInvalidShapeAndTypes) {
    failure(422, [] { validate_registration(Json::array()); });
    for (const auto &key : {"publicId", "url", "name", "version", "apiKey"}) {
        auto b = body();
        b[key] = 42;
        failure(422, [&] { validate_registration(b); });
        b.erase(key);
        if (std::string(key) != "version")
            failure(422, [&] { validate_registration(b); });
        else
            EXPECT_EQ("0.0.0", validate_registration(b).version);
    }
}
TEST(Registration, SupportsBoundedPublicIdentitiesWithoutNewRequiredFields) {
    auto b = body();
    b["publicId"] = std::string(128, 's');
    EXPECT_EQ(128, validate_registration(b).public_id.size());
    b["publicId"] = "short-code";
    EXPECT_EQ("short-code", validate_registration(b).public_id);
    b["publicId"] = std::string(129, 's');
    failure(422, [&] { validate_registration(b); });
}
TEST(Registration, ValidatesHttpsUrlAndBoundedFields) {
    for (const auto &url : {"http://server.baander.app", "https:///missing-host",
                            "https://user:pass@server.baander.app",
                            "https://server.baander.app/#fragment", "https://server.baander.app/\n",
                            "https://127.0.0.1", "https://[::1]", "https://%31%32%37.0.0.1"}) {
        auto b = body();
        b["url"] = url;
        failure(422, [&] { validate_registration(b); });
    }
    auto b = body();
    b["name"] = std::string(129, 'n');
    failure(422, [&] { validate_registration(b); });
    b = body();
    b["publicId"] = "wrong/identity";
    failure(422, [&] { validate_registration(b); });
}
TEST(Database, RejectsHttpErrorsStatementErrorsMissingOrExtraResults) {
    auto r = validate_registration(body());
    failure(503, [&] { register_result(r, 503, committed_registration()); });
    auto response = committed_registration();
    response["results"][0]["error"] = "failure";
    failure(503, [&] { register_result(r, 200, response); });
    response = committed_registration();
    response["error"] = "failure";
    failure(503, [&] { register_result(r, 200, response); });
    response = committed_registration();
    response["results"].erase(0);
    failure(503, [&] { register_result(r, 200, response); });
    response = committed_registration();
    response["results"].push_back(Json::object());
    failure(503, [&] { register_result(r, 200, response); });
}
TEST(Registration, OwnershipConflictAndMalformedResultFailClosed) {
    auto r = validate_registration(body());
    auto response = committed_registration();
    response["results"][1]["rows"] = Json::array();
    failure(403, [&] { register_result(r, 200, response); });
    response = committed_registration();
    response["results"][1]["rows"][0]["revision"] = "1";
    failure(503, [&] { register_result(r, 200, response); });
}
TEST(Lookup, ExplicitLinearizableRequestNeverSelectsCredentials) {
    auto request = lookup_request("Baander_server_0000001");
    EXPECT_EQ("/db/query?level=linearizable&associative", request.target);
    EXPECT_EQ(std::string::npos, request.statements.dump().find("credential"));
}
TEST(Lookup, KeepsOfflineIdentityReservedAndFiltersUnexpectedSecretColumns) {
    Json response = {
        {"results",
         Json::array(
             {Json{{"types", lookup_types()},
                   {"rows", Json::array({Json{{"public_id", "Baander_server_0000001"},
                                              {"url", "https://server.baander.app"},
                                              {"name", "Baander"},
                                              {"version", "1.0.0"},
                                              {"updated_ms", 1000},
                                              {"last_seen_ms", 1000},
                                              {"revision", 1},
                                              {"credential_digest", "must-never-escape"}}})}}})}};
    failure(404, [&] { lookup_result(200, response, 601000); });
    auto result = lookup_result(200, response, 600999);
    EXPECT_EQ("Baander_server_0000001", result["data"]["publicId"].get<std::string>());
    EXPECT_EQ(std::string::npos, result.dump().find("must-never-escape"));
}
TEST(Lookup, NoQuorumOrMalformedResponseNeverBecomesNotFound) {
    failure(503,
            [] { lookup_result(200, Json{{"results", Json::array({Json::object()})}}, 1000); });
    failure(503, [] { lookup_result(503, Json::object(), 1000); });
    failure(503, [] {
        lookup_result(200, Json{{"results", Json::array({Json{{"error", "no leader"}}})}}, 1000);
    });
    failure(404, [] {
        lookup_result(200,
                      Json{{"results", Json::array({Json{{"types", lookup_types()},
                                                         {"rows", Json::array()}}})}},
                      1000);
    });
}
TEST(Schema, IdempotentSchemaNeverDropsReservationsOrStoresRawCredential) {
    auto s = schema_request();
    EXPECT_EQ("/db/request?transaction&level=linearizable&associative", s.target);
    EXPECT_EQ(4, s.statements.size());
    EXPECT_NE(std::string::npos, s.statements.dump().find("schema_migrations"));
    EXPECT_NE(std::string::npos, s.statements.dump().find("checksum"));
    EXPECT_NE(std::string::npos, s.statements.dump().find("updated_ms"));
    EXPECT_EQ(std::string::npos, s.statements.dump().find("UNIQUE"));
    EXPECT_EQ(std::string::npos, s.statements.dump().find("DROP"));
    EXPECT_EQ(std::string::npos, s.statements.dump().find("api_key"));
}

TEST(Schema, RejectsChecksumMismatchUnsupportedVersionAndMissingMetadata) {
    const auto request = schema_request();
    auto response =
        Json{{"results",
              Json::array(
                  {Json::object(), Json::object(), Json::object(),
                   Json{{"types", Json{{"version", "integer"}, {"checksum", "text"}}},
                        {"rows", Json::array({Json{{"version", 1},
                                                   {"checksum", request.statements[2][1]}}})}}})}};
    EXPECT_NO_THROW(validate_schema_result(200, response));
    auto wrong = response;
    wrong["results"][3]["rows"][0]["checksum"] = std::string(64, '0');
    failure(503, [&] { validate_schema_result(200, wrong); });
    wrong = response;
    wrong["results"][3]["rows"][0]["version"] = 2;
    failure(503, [&] { validate_schema_result(200, wrong); });
    wrong = response;
    wrong["results"][3].erase("types");
    failure(503, [&] { validate_schema_result(200, wrong); });
}

TEST(Registration, Accepts128CharacterUnicodeNameAndRejects129) {
    auto registration = body();
    std::string name;
    for (unsigned i = 0; i < 128; ++i)
        name += "å";
    registration["name"] = name;
    EXPECT_EQ(name, validate_registration(registration).name);
    registration["name"] = name + "å";
    failure(422, [&] { validate_registration(registration); });
}
