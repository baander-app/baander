// SPDX-License-Identifier: Apache-2.0
#include "registry/core.hpp"
#include <iostream>
using namespace registry;
int main(int argc, char **) {
    if (argc > 1) {
        Json input;
        std::cin >> input;
        try {
            Json output;
            if (input["kind"] == "schema" || input["kind"] == "schema-invalid") {
                validate_schema_result(200, input["response"]);
                output = Json{{"ready", true}};
            } else if (input["kind"] == "maintenance") {
                validate_database_result(200, input["response"], input["statements"].size());
                output = Json{{"applied", true}};
            } else if (input["kind"] == "lookup" || input["kind"] == "offline") {
                output = lookup_result(200, input["response"],
                                       input["kind"] == "offline" ? 1000000 : 3000);
            } else {
                Registration registration;
                registration.public_id = input["statements"][0][1]["public_id"].get<std::string>();
                output = register_result(registration, 200, input["response"]);
            }
            std::cout << Json{{"status", 200}, {"body", output}}.dump();
        } catch (const Failure &error) {
            std::cout << Json{{"status", error.status}}.dump();
        }
        return 0;
    }
    Json body{{"publicId", "native-registry-fixture"},
              {"url", "https://server.baander.app"},
              {"name", "Fixture"},
              {"version", "1.0.0"},
              {"apiKey", std::string(64, 'a')}};
    auto registration = validate_registration(body);
    Json operations = Json::array();
    auto add = [&](const char *name, DatabaseRequest request, const char *kind,
                   std::int64_t revision = 0) {
        operations.push_back({{"name", name},
                              {"target", request.target},
                              {"statements", request.statements},
                              {"kind", kind},
                              {"revision", revision}});
    };
    add("schema", schema_request(), "schema");
    add("anonymous first claim is denied", register_request(registration, 900, false), "conflict");
    add("first claim", register_request(registration, 1000, true), "register", 1);
    add("heartbeat retry", register_request(registration, 2000, false), "register", 2);
    add("schema reopening", schema_request(), "schema");
    add("preserved after reopening", lookup_request(registration.public_id), "lookup", 2);
    body["apiKey"] = std::string(64, 'b');
    add("different credential cannot claim", register_request(validate_registration(body), 3000, true),
        "conflict");
    body["apiKey"] = std::string(64, 'a');
    body["publicId"] = "different-public-id";
    add("credential can own a separate identity",
        register_request(validate_registration(body), 3000, true), "register", 1);
    body["publicId"] = registration.public_id;
    body["name"] = "Updated fixture";
    add("owned metadata update", register_request(validate_registration(body), 500, false), "register", 3);
    add("lookup latest revision", lookup_request(registration.public_id), "lookup", 3);
    add("offline does not return stale URL", lookup_request(registration.public_id), "offline");
    add("inject checksum mismatch",
        {"/db/execute?transaction",
         Json::array({Json::array(
             {"UPDATE schema_migrations SET checksum=? WHERE version=1", std::string(64, '0')})})},
        "maintenance");
    add("checksum mismatch blocks readiness", schema_request(), "schema-invalid");
    std::cout << operations.dump();
}
