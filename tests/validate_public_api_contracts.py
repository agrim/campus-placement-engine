#!/usr/bin/env python3
"""Pinned Draft 2020-12 and bounded OpenAPI validation for public API v1."""

from __future__ import annotations

import json
import sys
from pathlib import Path
from typing import Any, Iterable

try:
    from jsonschema import Draft202012Validator
    from referencing import Registry, Resource
except ModuleNotFoundError as failure:
    print(
        "Pinned public API validator dependencies are unavailable: " + str(failure),
        file=sys.stderr,
    )
    raise SystemExit(2) from failure


ROOT = Path(__file__).resolve().parent.parent
CONTRACTS = ROOT / "contracts"


def load(relative: str) -> dict[str, Any]:
    with (ROOT / relative).open("r", encoding="utf-8") as handle:
        value = json.load(handle)
    if not isinstance(value, dict):
        raise TypeError(f"{relative} must contain a JSON object")
    return value


def assert_valid(
    label: str,
    validator: Draft202012Validator,
    instance: dict[str, Any],
) -> None:
    errors = sorted(validator.iter_errors(instance), key=lambda error: list(error.path))
    if errors:
        locations = ["/".join(map(str, error.absolute_path)) or "<root>" for error in errors]
        raise AssertionError(f"{label} failed at {', '.join(locations)}")


def walk_refs(value: Any) -> Iterable[str]:
    if isinstance(value, dict):
        for key, child in value.items():
            if key == "$ref" and isinstance(child, str):
                yield child
            else:
                yield from walk_refs(child)
    elif isinstance(value, list):
        for child in value:
            yield from walk_refs(child)


def resolve_pointer(document: dict[str, Any], reference: str) -> Any:
    if not reference.startswith("#/"):
        raise AssertionError(f"not an internal JSON pointer: {reference}")
    current: Any = document
    for raw_part in reference[2:].split("/"):
        part = raw_part.replace("~1", "/").replace("~0", "~")
        if not isinstance(current, dict) or part not in current:
            raise AssertionError(f"unresolved OpenAPI reference: {reference}")
        current = current[part]
    return current


def resolve_ref_object(document: dict[str, Any], value: Any) -> Any:
    current = value
    seen: set[str] = set()
    while isinstance(current, dict) and set(current) == {"$ref"}:
        reference = current["$ref"]
        if not isinstance(reference, str) or not reference.startswith("#/"):
            break
        if reference in seen:
            raise AssertionError(f"cyclic OpenAPI reference: {reference}")
        seen.add(reference)
        current = resolve_pointer(document, reference)
    return current


def validate_openapi(openapi: dict[str, Any], schema_names: set[str]) -> None:
    if openapi.get("openapi") != "3.1.0":
        raise AssertionError("OpenAPI version must be exactly 3.1.0")
    if openapi.get("jsonSchemaDialect") != "https://json-schema.org/draft/2020-12/schema":
        raise AssertionError("OpenAPI must select Draft 2020-12")
    if openapi.get("security") != [{"bearerAuth": []}]:
        raise AssertionError("OpenAPI must require only the exact Bearer scheme")

    expected_paths = {
        "/api/v1",
        "/api/v1/opportunities",
        "/api/v1/opportunities/{public_id}",
        "/api/v1/applications",
        "/api/v1/applications/{public_id}",
        "/api/v1/applications/{public_id}/transitions",
    }
    paths = openapi.get("paths")
    if not isinstance(paths, dict) or set(paths) != expected_paths:
        raise AssertionError("OpenAPI public path set differs")
    http_methods = {"get", "put", "post", "delete", "options", "head", "patch", "trace"}
    operation_ids: set[str] = set()
    for path, path_item in paths.items():
        if not isinstance(path_item, dict):
            raise AssertionError(f"OpenAPI path item is invalid: {path}")
        methods = set(path_item).intersection(http_methods)
        expected_methods = (
            {"post"}
            if path == "/api/v1/applications/{public_id}/transitions"
            else {"get", "head"}
        )
        if methods != expected_methods:
            raise AssertionError(f"OpenAPI method map differs: {path}")
        for method in methods:
            operation = path_item[method]
            command = path == "/api/v1/applications/{public_id}/transitions"
            if not isinstance(operation, dict) or ("requestBody" in operation) != command:
                raise AssertionError(f"OpenAPI operation request body differs: {method} {path}")
            operation_id = operation.get("operationId")
            if not isinstance(operation_id, str) or operation_id in operation_ids:
                raise AssertionError("OpenAPI operation IDs must be present and unique")
            operation_ids.add(operation_id)
            responses = operation.get("responses")
            if not isinstance(responses, dict) or "200" not in responses:
                raise AssertionError(f"OpenAPI operation has no explicit 200 response: {method} {path}")
            if command:
                expected_statuses = {
                    "200", "400", "401", "403", "404", "409", "413", "414",
                    "415", "422", "428", "429", "431", "500", "503",
                }
            else:
                expected_statuses = {"200", "400", "401", "414", "429", "431", "500", "503"}
                if path != "/api/v1":
                    expected_statuses.add("403")
                item_operation = path.endswith("/{public_id}")
                if item_operation:
                    expected_statuses.update({"304", "404"})
            if set(responses) != expected_statuses:
                raise AssertionError(f"OpenAPI response status map differs: {method} {path}")

            expected_bound_refs = {
                "414": "#/components/responses/RequestTargetTooLarge"
                if method != "head"
                else "#/components/responses/HeadRequestTargetTooLarge",
                "431": "#/components/responses/RequestHeadersTooLarge"
                if method != "head"
                else "#/components/responses/HeadRequestHeadersTooLarge",
            }
            for status, expected_ref in expected_bound_refs.items():
                if responses[status] != {"$ref": expected_ref}:
                    raise AssertionError(
                        f"OpenAPI {status} reusable response differs: {method} {path}"
                    )

            for status, response_value in responses.items():
                response = resolve_ref_object(openapi, response_value)
                if not isinstance(response, dict):
                    raise AssertionError(f"OpenAPI response is invalid: {status} {method} {path}")
                expected_headers = {"X-Request-ID"}
                if status == "401":
                    expected_headers.add("WWW-Authenticate")
                if status in {"429", "503"}:
                    expected_headers.add("Retry-After")
                item_operation = path in {
                    "/api/v1/opportunities/{public_id}",
                    "/api/v1/applications/{public_id}",
                }
                if (item_operation and status in {"200", "304"}) or (command and status == "200"):
                    expected_headers.add("ETag")
                headers = response.get("headers", {})
                if not isinstance(headers, dict) or set(headers) != expected_headers:
                    raise AssertionError(
                        f"OpenAPI response header map differs: {status} {method} {path}"
                    )
                expected_content = (
                    {"application/json"}
                    if method != "head" and status != "304"
                    else set()
                )
                content = response.get("content", {})
                if not isinstance(content, dict) or set(content) != expected_content:
                    raise AssertionError(
                        f"OpenAPI response content map differs: {status} {method} {path}"
                    )

    collection_parameter_refs = {
        "#/components/parameters/UpdatedAfter",
        "#/components/parameters/Limit",
        "#/components/parameters/Cursor",
    }
    for path in ("/api/v1/opportunities", "/api/v1/applications"):
        for method in ("get", "head"):
            parameters = paths[path][method].get("parameters")
            refs = {
                item.get("$ref")
                for item in parameters
                if isinstance(parameters, list) and isinstance(item, dict)
            }
            if refs != collection_parameter_refs:
                raise AssertionError(f"OpenAPI collection parameters differ: {method} {path}")

    command = paths["/api/v1/applications/{public_id}/transitions"]["post"]
    command_parameters = command.get("parameters")
    command_parameter_refs = {
        item.get("$ref")
        for item in command_parameters
        if isinstance(command_parameters, list) and isinstance(item, dict)
    }
    if command_parameter_refs != {
        "#/components/parameters/IdempotencyKey",
        "#/components/parameters/IfMatch",
    }:
        raise AssertionError("OpenAPI command header parameters differ")
    if command.get("requestBody") != {
        "required": True,
        "content": {
            "application/json": {
                "schema": {"$ref": "schemas/api-v1-application-transition-request.schema.json"}
            }
        },
    }:
        raise AssertionError("OpenAPI command request body differs")
    if command.get("x-cpe-required-scope") != "applications.transition":
        raise AssertionError("OpenAPI command scope differs")
    if command.get("x-cpe-idempotency-horizon-hours") != 48:
        raise AssertionError("OpenAPI command retry horizon differs")
    if openapi["components"]["parameters"]["IdempotencyKey"]["schema"] != {
        "type": "string", "pattern": "^[a-f0-9]{32,64}$",
    }:
        raise AssertionError("OpenAPI Idempotency-Key grammar differs")
    if openapi["components"]["parameters"]["IfMatch"]["schema"] != {
        "type": "string", "pattern": '^\\"[a-f0-9]{64}\\"$',
    }:
        raise AssertionError("OpenAPI If-Match grammar differs")

    schemes = openapi.get("components", {}).get("securitySchemes", {})
    if schemes != {
        "bearerAuth": {
            "type": "http",
            "scheme": "bearer",
            "bearerFormat": "CPE API token",
        }
    }:
        raise AssertionError("OpenAPI security scheme differs")
    limit = openapi["components"]["parameters"]["Limit"]["schema"]
    if limit != {"type": "integer", "minimum": 1, "maximum": 100, "default": 50}:
        raise AssertionError("OpenAPI limit contract differs")

    for reference in walk_refs(openapi):
        if reference.startswith("#/"):
            resolve_pointer(openapi, reference)
            continue
        if not reference.startswith("schemas/"):
            raise AssertionError(f"OpenAPI contains an unsupported external reference: {reference}")
        name = Path(reference).name
        if name not in schema_names or not (CONTRACTS / reference).is_file():
            raise AssertionError(f"OpenAPI schema reference is unresolved: {reference}")

    examples = openapi.get("x-cpe-contract-examples")
    if not isinstance(examples, dict) or set(examples) != {
        "service",
        "opportunity_item",
        "opportunity_collection",
        "application_item",
        "application_collection",
        "application_transition_request",
        "application_transition_response",
        "error",
    }:
        raise AssertionError("OpenAPI contract example declaration differs")
    for relative in examples.values():
        if not isinstance(relative, str) or not (CONTRACTS / relative).is_file():
            raise AssertionError("OpenAPI contract example is unresolved")


def main() -> None:
    schema_paths = sorted((CONTRACTS / "schemas").glob("api-v1-*.schema.json"))
    if len(schema_paths) != 11:
        raise AssertionError("Public API v1 requires exactly eleven strict schemas")
    schemas: dict[str, dict[str, Any]] = {}
    resources: list[tuple[str, Resource[Any]]] = []
    for path in schema_paths:
        relative = str(path.relative_to(ROOT))
        schema = load(relative)
        Draft202012Validator.check_schema(schema)
        schema_id = schema.get("$id")
        if not isinstance(schema_id, str) or not schema_id.startswith("urn:cpe:api:v1:"):
            raise AssertionError(f"{relative} requires a stable API v1 URN")
        if schema_id in schemas:
            raise AssertionError(f"duplicate API schema ID: {schema_id}")
        if schema.get("type") == "object" and schema.get("additionalProperties") is not False:
            raise AssertionError(f"{relative} must be strict at its root")
        schemas[schema_id] = schema
        resources.append((schema_id, Resource.from_contents(schema)))
    registry = Registry().with_resources(resources)
    validators = {
        schema_id: Draft202012Validator(schema, registry=registry)
        for schema_id, schema in schemas.items()
    }

    cases = [
        ("service example", "urn:cpe:api:v1:service", "contracts/examples/api-v1-service.json"),
        ("opportunity item example", "urn:cpe:api:v1:opportunity-item", "contracts/examples/api-v1-opportunity-item.json"),
        ("opportunity collection example", "urn:cpe:api:v1:opportunity-collection", "contracts/examples/api-v1-opportunity-collection.json"),
        ("application item example", "urn:cpe:api:v1:application-item", "contracts/examples/api-v1-application-item.json"),
        ("application collection example", "urn:cpe:api:v1:application-collection", "contracts/examples/api-v1-application-collection.json"),
        ("application transition request example", "urn:cpe:api:v1:application-transition-request", "contracts/examples/api-v1-application-transition-request.json"),
        ("application transition response example", "urn:cpe:api:v1:application-item", "contracts/examples/api-v1-application-transition-response.json"),
        ("error example", "urn:cpe:api:v1:error", "contracts/examples/api-v1-error.json"),
        ("frozen opportunity consumer", "urn:cpe:api:v1:opportunity-item", "contracts/fixtures/api-v1-opportunity.consumer.json"),
        ("frozen application consumer", "urn:cpe:api:v1:application-item", "contracts/fixtures/api-v1-application.consumer.json"),
        ("frozen application transition consumer", "urn:cpe:api:v1:application-item", "contracts/fixtures/api-v1-application-transition.consumer.json"),
    ]
    for label, schema_id, relative in cases:
        assert_valid(label, validators[schema_id], load(relative))

    future = load("contracts/fixtures/api-v1-opportunity.future-field.consumer.json")
    if validators["urn:cpe:api:v1:opportunity-item"].is_valid(future):
        raise AssertionError("strict opportunity contract accepted an undeclared producer field")

    expected_opportunity_fields = {
        "id", "cycle_id", "organization_id", "organization_code", "organization_name",
        "opportunity_key", "title", "status", "created_at", "updated_at",
    }
    expected_application_fields = {
        "id", "participant_id", "opportunity_id", "status", "aggregate_version",
        "created_at", "updated_at",
    }
    if set(schemas["urn:cpe:api:v1:opportunity"]["required"]) != expected_opportunity_fields:
        raise AssertionError("opportunity producer allowlist differs")
    if set(schemas["urn:cpe:api:v1:application"]["required"]) != expected_application_fields:
        raise AssertionError("application producer allowlist differs")

    catalog = load("contracts/public-integration.v1.json")
    if catalog != {
        "schema": 1,
        "event_schemas": {"application.status_changed": [1]},
        "api_scopes": ["opportunities.read", "applications.read", "applications.transition"],
        "engine_api": ["v1"],
    }:
        raise AssertionError("public integration catalog differs")

    openapi = load("contracts/openapi.v1.json")
    validate_openapi(openapi, {path.name for path in schema_paths})
    print("PASS OpenAPI 3.1 and Draft 2020-12 public API contracts")


if __name__ == "__main__":
    main()
