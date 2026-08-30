#!/usr/bin/env python3
"""Independent Draft 2020-12 validation for the frozen public event contract."""

from __future__ import annotations

import json
import sys
from pathlib import Path
from typing import Any

try:
    from jsonschema import Draft202012Validator
    from referencing import Registry, Resource
except ModuleNotFoundError as failure:
    print(
        "Pinned Draft 2020-12 validator dependencies are unavailable: " + str(failure),
        file=sys.stderr,
    )
    raise SystemExit(2) from failure


ROOT = Path(__file__).resolve().parent.parent


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


def main() -> None:
    envelope = load("contracts/schemas/public-event-envelope.v1.schema.json")
    event = load("contracts/schemas/application.status_changed.v1.schema.json")
    example = load("contracts/examples/application.status_changed.v1.json")
    frozen = load("contracts/fixtures/application.status_changed.v1.consumer.json")
    future_optional = load(
        "contracts/fixtures/application.status_changed.v1.future-optional.consumer.json"
    )

    Draft202012Validator.check_schema(envelope)
    Draft202012Validator.check_schema(event)
    event_id = event.get("$id")
    if not isinstance(event_id, str) or not event_id.startswith("urn:"):
        raise AssertionError("event schema requires an absolute URN $id")
    expected_reference = event_id + "#/$defs/data"
    actual_reference = envelope.get("properties", {}).get("data", {}).get("$ref")
    if actual_reference != expected_reference:
        raise AssertionError("envelope data $ref must resolve from the event schema $id")

    registry = Registry().with_resources(
        [
            (str(envelope["$id"]), Resource.from_contents(envelope)),
            (event_id, Resource.from_contents(event)),
        ]
    )
    envelope_validator = Draft202012Validator(envelope, registry=registry)
    event_validator = Draft202012Validator(event, registry=registry)
    for label, instance in (("example", example), ("frozen fixture", frozen)):
        assert_valid(label + " against envelope", envelope_validator, instance)
        assert_valid(label + " against event", event_validator, instance)

    if envelope_validator.is_valid(future_optional):
        raise AssertionError("strict envelope accepted future optional producer fields")
    if event_validator.is_valid(future_optional):
        raise AssertionError("strict event schema accepted future optional producer fields")

    print("PASS Draft 2020-12 public event schemas")


if __name__ == "__main__":
    main()
