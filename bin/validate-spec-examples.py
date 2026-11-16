#!/usr/bin/env python3
"""Checks docs/schema/suite-event-v1.schema.json against docs/examples/*.json.

Needs the Python package "jsonschema" (pip install jsonschema); it is not part of this repository.
Usage: python3 bin/validate-spec-examples.py
Exit code 0: the schema is a valid draft 2020-12 schema, every example is valid, the file name of every
example equals its "type", and every counter-example below is rejected.
"""
import copy
import glob
import json
import os
import sys

from jsonschema import Draft202012Validator

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SCHEMA = os.path.join(ROOT, "docs", "schema", "suite-event-v1.schema.json")
EXAMPLES = sorted(glob.glob(os.path.join(ROOT, "docs", "examples", "*.json")))


def mutate(doc, path, value="__delete__"):
    doc = copy.deepcopy(doc)
    node = doc
    for part in path[:-1]:
        node = node[part]
    if value == "__delete__":
        del node[path[-1]]
    else:
        node[path[-1]] = value
    return doc


def main():
    with open(SCHEMA, encoding="utf-8") as handle:
        schema = json.load(handle)
    Draft202012Validator.check_schema(schema)
    validator = Draft202012Validator(schema)
    failed = 0
    if len(EXAMPLES) < 3:
        print("FAIL fewer than three examples")
        failed += 1
    docs = {}
    for path in EXAMPLES:
        name = os.path.basename(path)
        with open(path, encoding="utf-8") as handle:
            doc = json.load(handle)
        docs[name] = doc
        errors = sorted(validator.iter_errors(doc), key=lambda e: list(e.path))
        if name != doc.get("type", "") + ".json":
            errors.append("file name does not equal the type")
        for error in errors:
            print("FAIL", name, getattr(error, "message", error))
        failed += len(errors)
        if not errors:
            print("ok  ", name)

    base = docs["pass.ticket.checked_in.json"]
    caused = next(d for d in docs.values() if d["causation_id"])
    counter = {
        "missing id": mutate(base, ["id"]),
        "id is not a UUID v4": mutate(base, ["id"], "b81bb38e-5237-5cc6-9883-f1d39783f237"),
        "unknown top-level field": mutate(base, ["payload"], {}),
        "unknown product prefix in type": mutate(base, ["type"], "shop.order.paid"),
        "local time instead of UTC": mutate(base, ["occurred_at"], "2026-11-16 18:02:11"),
        "e-mail address as subject": mutate(base, ["subject", "id"], "kunde@example.com"),
        "data carries a code": mutate(base, ["data", "code"], "ABCD-1234"),
        "data carries a voucher code": mutate(base, ["data", "voucher_code"], "ABCD-1234"),
        "partner code under another name": mutate(base, ["data", "partner_code_secret"], "x"),
        "data carries a ticket code": mutate(base, ["data", "ticket_code"], "ABCD-1234"),
        "data carries an e-mail": mutate(base, ["data", "customer_email"], "kunde@example.com"),
        "data carries a license key": mutate(base, ["data", "license_key"], "WPLC-XXXX"),
        "data carries a name": mutate(base, ["data", "attendee_name"], "Erika Muster"),
        "nested data": mutate(base, ["data", "order"], {"id": 1}),
        "actor is not a user ID": mutate(base, ["actor_user_id"], "erika"),
        "causation without workflow": mutate(caused, ["caused_by_workflow_id"]),
        "causation with depth 0": mutate(caused, ["chain_depth"], 0),
        "workflow without causation": mutate(base, ["caused_by_workflow_id"], 12),
        "depth without causation": mutate(base, ["chain_depth"], 2),
        "spec version of another major": mutate(base, ["spec_version"], "2.0"),
    }
    for label, doc in counter.items():
        if validator.is_valid(doc):
            print("FAIL counter-example accepted:", label)
            failed += 1
        else:
            print("ok   rejected:", label)
    print("%d example(s), %d counter-example(s), %d failure(s)" % (len(EXAMPLES), len(counter), failed))
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
