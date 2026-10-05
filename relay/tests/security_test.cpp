// SPDX-License-Identifier: Apache-2.0
#include "registry/core.hpp"
#include <gtest/gtest.h>
#include <limits>

using namespace registry;

namespace {
constexpr const char *secret_marker = "private-database-detail-must-not-escape";

Json registration_body() {
    return {{"publicId", "security_server"},
            {"url", "https://security.baander.app/base"},
            {"name", "Security server"},
            {"version", "1.0.0"},
            {"apiKey", std::string(64, 'a')}};
}

Json registration_response() {
    return {{"results", Json::array({Json{{"rows_affected", 1}},
                                    Json{{"types", {{"revision", "integer"},
                                                    {"updated_ms", "integer"},
                                                    {"last_seen_ms", "integer"}}},
                                         {"rows", Json::array({Json{{"revision", 1},
                                                                    {"updated_ms", 1000},
                                                                    {"last_seen_ms", 1000}}})}}})}};
}

Json lookup_response() {
    return {{"results", Json::array({Json{{"types", {{"public_id", "text"},
                                                  {"url", "text"},
                                                  {"name", "text"},
                                                  {"version", "text"},
                                                  {"revision", "integer"},
                                                  {"updated_ms", "integer"},
                                                  {"last_seen_ms", "integer"}}},
                                       {"rows", Json::array({Json{{"public_id", "security_server"},
                                                                  {"url", "https://security.baander.app"},
                                                                  {"name", "Security server"},
                                                                  {"version", "1.0.0"},
                                                                  {"revision", 1},
                                                                  {"updated_ms", 1000},
                                                                  {"last_seen_ms", 1000}}})}}})}};
}

template <class Operation> void expect_safe_failure(unsigned status, Operation operation) {
    try {
        operation();
        FAIL() << "Expected fail-closed response";
    } catch (const Failure &error) {
        EXPECT_EQ(status, error.status);
        const std::string message = error.what();
        EXPECT_EQ(std::string::npos, message.find(secret_marker));
        EXPECT_EQ(std::string::npos, message.find(std::string(64, 'a')));
        EXPECT_EQ(std::string::npos,
                  message.find("ffe054fe7ae0cb6dc65c3af9b61d5209f439851db43d0ba5997337df154668eb"));
    }
}
} // namespace

TEST(SecurityRegistration, HashesTheCredentialBytesWithKnownSha256Vectors) {
    auto body = registration_body();
    EXPECT_EQ("ffe054fe7ae0cb6dc65c3af9b61d5209f439851db43d0ba5997337df154668eb",
              validate_registration(body).credential_digest);
    body["apiKey"] = std::string(64, '0');
    EXPECT_EQ("60e05bd1b195af2f94112fa7197a5c88289058840ce7c6df9693756bc6250f55",
              validate_registration(body).credential_digest);
}

TEST(SecurityRegistration, RejectsEmptyAndControlCharacterFields) {
    for (const auto *key : {"publicId", "url", "name", "version", "apiKey"}) {
        SCOPED_TRACE(key);
        for (const auto &value : {std::string(), std::string("value\0suffix", 12),
                                 std::string("value\rsuffix"), std::string("value\x7f" "suffix")}) {
            auto body = registration_body();
            body[key] = value;
            expect_safe_failure(422, [&] { validate_registration(body); });
        }
    }
}

TEST(SecurityRegistration, EnforcesUrlAndVersionLengthBoundaries) {
    auto body = registration_body();
    const std::string prefix = "https://security.baander.app/";
    body["url"] = prefix + std::string(2048 - prefix.size(), 'p');
    body["version"] = std::string(64, 'v');
    EXPECT_EQ(2048, validate_registration(body).url.size());
    EXPECT_EQ(64, validate_registration(body).version.size());
    body["url"] = body["url"].get<std::string>() + "p";
    expect_safe_failure(422, [&] { validate_registration(body); });
    body = registration_body();
    body["version"] = std::string(65, 'v');
    expect_safe_failure(422, [&] { validate_registration(body); });
}

TEST(SecurityRegistration, RejectsUrlAuthorityAndSchemeConfusion) {
    for (const auto *url : {"//security.baander.app", "https:security.baander.app",
                           "https://", "https://user@security.baander.app",
                           "https://user%40name@security.baander.app",
                           "https://security.baander.app#", "https://security.baander.app/#token",
                           "https://sec%75rity.baander.app", "https://192.0.2.1/base",
                           "https://[2001:db8::1]/base", "https://[::ffff:192.0.2.1]/base",
                           "https://security.baander.app\\@127.0.0.1"}) {
        SCOPED_TRACE(url);
        auto body = registration_body();
        body["url"] = url;
        expect_safe_failure(422, [&] { validate_registration(body); });
    }
    auto body = registration_body();
    body["url"] = "https://security.baander.app:8443/base?node=one%20two";
    EXPECT_EQ(body["url"], validate_registration(body).url);
}

TEST(SecurityRegistration, RejectsInvalidUtf8NamesAndCountsFourByteScalars) {
    for (const auto &name : {std::string("\xc0\xaf", 2), std::string("\xed\xa0\x80", 3),
                            std::string("\xf4\x90\x80\x80", 4), std::string("\xe2\x82", 2)}) {
        auto body = registration_body();
        body["name"] = name;
        expect_safe_failure(422, [&] { validate_registration(body); });
    }
    std::string name;
    for (unsigned i = 0; i < 128; ++i)
        name += "\xf0\x9f\x8e\xb5";
    auto body = registration_body();
    body["name"] = name;
    EXPECT_EQ(name, validate_registration(body).name);
    body["name"] = name + "\xf0\x9f\x8e\xb5";
    expect_safe_failure(422, [&] { validate_registration(body); });
}

TEST(SecurityDatabase, RejectsMalformedEnvelopesWithoutLeakingDatabaseErrors) {
    const auto registration = validate_registration(registration_body());
    for (const auto &malformed : {Json(), Json::array(), Json(secret_marker),
                                 Json{{"results", nullptr}}, Json{{"results", Json::object()}},
                                 Json{{"results", Json::array({nullptr, nullptr})}}}) {
        SCOPED_TRACE(malformed.dump());
        expect_safe_failure(503, [&] { register_result(registration, 200, malformed); });
        expect_safe_failure(503, [&] { lookup_result(200, malformed, 1000); });
    }
    for (unsigned status : {201U, 204U, 301U, 401U, 403U, 404U, 429U, 500U, 503U}) {
        SCOPED_TRACE(status);
        expect_safe_failure(503, [&] { register_result(registration, status, registration_response()); });
        expect_safe_failure(503, [&] { lookup_result(status, lookup_response(), 1000); });
    }
    for (unsigned statement = 0; statement < 2; ++statement) {
        auto response = registration_response();
        response["results"][statement]["error"] = secret_marker;
        expect_safe_failure(503, [&] { register_result(registration, 200, response); });
    }
    auto response = lookup_response();
    response["error"] = secret_marker;
    expect_safe_failure(503, [&] { lookup_result(200, response, 1000); });
    response = lookup_response();
    response["results"][0]["error"] = secret_marker;
    expect_safe_failure(503, [&] { lookup_result(200, response, 1000); });
}

TEST(SecurityRegistration, RequiresAuthoritativeTypesBeforeReportingOwnershipConflict) {
    const auto registration = validate_registration(registration_body());
    auto response = registration_response();
    response["results"][1]["rows"] = Json::array();
    expect_safe_failure(403, [&] { register_result(registration, 200, response); });
    response["results"][1].erase("rows");
    expect_safe_failure(403, [&] { register_result(registration, 200, response); });
    response["results"][1].erase("types");
    expect_safe_failure(503, [&] { register_result(registration, 200, response); });
    for (const auto *column : {"revision", "updated_ms", "last_seen_ms"}) {
        SCOPED_TRACE(column);
        for (const auto &type : {Json(), Json("text"), Json(1)}) {
            response = registration_response();
            response["results"][1]["types"][column] = type;
            expect_safe_failure(503, [&] { register_result(registration, 200, response); });
        }
    }
}

TEST(SecurityDatabase, RejectsMalformedAndAmbiguousRows) {
    const auto registration = validate_registration(registration_body());
    for (const auto &rows : {Json(), Json::object(), Json(secret_marker),
                            Json::array({nullptr}), Json::array({Json::array()}),
                            Json::array({Json::object(), Json::object()})}) {
        SCOPED_TRACE(rows.dump());
        auto response = registration_response();
        response["results"][1]["rows"] = rows;
        expect_safe_failure(503, [&] { register_result(registration, 200, response); });
        response = lookup_response();
        response["results"][0]["rows"] = rows;
        expect_safe_failure(503, [&] { lookup_result(200, response, 1000); });
    }
}

TEST(SecurityDatabase, RejectsMissingWrongTypeNegativeAndOverflowingNumbers) {
    const auto registration = validate_registration(registration_body());
    for (const auto *column : {"revision", "updated_ms", "last_seen_ms"}) {
        SCOPED_TRACE(column);
        for (const auto &value : {Json(), Json("1000"), Json(true), Json(1.0), Json(-1),
                                 Json(std::numeric_limits<std::uint64_t>::max()),
                                 Json(static_cast<std::uint64_t>(std::numeric_limits<std::int64_t>::max()) + 1)}) {
            SCOPED_TRACE(value.dump());
            auto response = registration_response();
            response["results"][1]["rows"][0][column] = value;
            expect_safe_failure(503, [&] { register_result(registration, 200, response); });
            response = lookup_response();
            response["results"][0]["rows"][0][column] = value;
            expect_safe_failure(503, [&] { lookup_result(200, response, 1000); });
        }
        auto response = registration_response();
        response["results"][1]["rows"][0].erase(column);
        expect_safe_failure(503, [&] { register_result(registration, 200, response); });
        response = lookup_response();
        response["results"][0]["rows"][0].erase(column);
        expect_safe_failure(503, [&] { lookup_result(200, response, 1000); });
    }
    auto response = registration_response();
    response["results"][1]["rows"][0]["revision"] = 0;
    expect_safe_failure(503, [&] { register_result(registration, 200, response); });
    response = lookup_response();
    response["results"][0]["rows"][0]["revision"] = 0;
    expect_safe_failure(503, [&] { lookup_result(200, response, 1000); });
}

TEST(SecurityLookup, RejectsMissingOrNonStringMetadata) {
    for (const auto *column : {"public_id", "url", "name", "version"}) {
        SCOPED_TRACE(column);
        for (const auto &value : {Json(), Json(1), Json(true), Json::array(), Json::object()}) {
            auto response = lookup_response();
            response["results"][0]["rows"][0][column] = value;
            expect_safe_failure(503, [&] { lookup_result(200, response, 1000); });
        }
        auto response = lookup_response();
        response["results"][0]["rows"][0].erase(column);
        expect_safe_failure(503, [&] { lookup_result(200, response, 1000); });
    }
}

TEST(SecurityDatabase, PublicResponsesWhitelistFieldsAndNeverReturnCredentials) {
    const auto registration = validate_registration(registration_body());
    auto response = registration_response();
    auto &row = response["results"][1]["rows"][0];
    row["apiKey"] = registration_body()["apiKey"];
    row["credential_digest"] = registration.credential_digest;
    row["debug"] = secret_marker;
    EXPECT_EQ(Json({{"data", {{"registered", true}, {"publicId", "security_server"},
                               {"revision", 1}, {"updatedAtMs", 1000}, {"lastHeartbeatMs", 1000}}}}),
              register_result(registration, 200, response));
    response = lookup_response();
    response["results"][0]["rows"][0]["apiKey"] = registration_body()["apiKey"];
    response["results"][0]["rows"][0]["credential_digest"] = registration.credential_digest;
    response["results"][0]["rows"][0]["debug"] = secret_marker;
    EXPECT_EQ(Json({{"data", {{"publicId", "security_server"},
                               {"url", "https://security.baander.app"}, {"name", "Security server"},
                               {"version", "1.0.0"}, {"revision", 1}, {"updatedAtMs", 1000},
                               {"lastHeartbeatMs", 1000}}}}), lookup_result(200, response, 1000));
}

TEST(SecurityLookup, OfflineBoundaryClockReversalAndExtremeTimesRemainSafe) {
    auto response = lookup_response();
    EXPECT_NO_THROW(lookup_result(200, response, 600999));
    expect_safe_failure(404, [&] { lookup_result(200, response, 601000); });
    EXPECT_NO_THROW(lookup_result(200, response, 0));
    expect_safe_failure(503, [&] { lookup_result(200, response, -1); });
    expect_safe_failure(404, [&] {
        lookup_result(200, response, std::numeric_limits<std::int64_t>::max());
    });
    response["results"][0]["rows"][0]["last_seen_ms"] = std::numeric_limits<std::int64_t>::max();
    EXPECT_NO_THROW(lookup_result(200, response, std::numeric_limits<std::int64_t>::max()));
    expect_safe_failure(503, [&] {
        register_request(validate_registration(registration_body()), -1);
    });
}
