#!/usr/bin/env python3
"""Checks docs/schema/suite-event-v1.schema.json and docs/catalog/suite-events-v1.json against docs/examples/*.json.

Needs the Python package "jsonschema" (pip install jsonschema); it is not part of this repository.
Usage: python3 bin/validate-spec-examples.py
Exit code 0: the schema is a valid draft 2020-12 schema, every example is valid, the file name of every
example equals its "type", every counter-example below is rejected, and the catalog holds: every example
type is in the catalog with the matching product and subject, its data carries only catalog fields, every
catalog field name passes the schema, and every type of a product marked "example_required" has an example.
"""
import copy
import glob
import json
import os
import sys

from jsonschema import Draft202012Validator

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SCHEMA = os.path.join(ROOT, "docs", "schema", "suite-event-v1.schema.json")
CATALOG = os.path.join(ROOT, "docs", "catalog", "suite-events-v1.json")
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


def check_catalog(validator, docs):
    """Checks the machine-readable catalog against the schema and the examples. Returns the failure count."""
    with open(CATALOG, encoding="utf-8") as handle:
        catalog = json.load(handle)
    failed = 0
    by_type = {}
    for product, entry in catalog["products"].items():
        for event in entry["events"]:
            if event["type"] in by_type:
                print("FAIL catalog: duplicate type", event["type"])
                failed += 1
            by_type[event["type"]] = (product, entry, event)
            probe = copy.deepcopy(docs["pass.ticket.checked_in.json"])
            probe["data"] = {field: 1 for field in event["data_fields"]}
            if not validator.is_valid(probe):
                print("FAIL catalog: a data field of", event["type"], "is rejected by the schema")
                failed += 1
            if entry.get("example_required") and event["type"] + ".json" not in docs:
                print("FAIL catalog: no example for", event["type"])
                failed += 1
    for name, doc in docs.items():
        found = by_type.get(doc.get("type"))
        if not found:
            print("FAIL catalog: example type not in catalog:", name)
            failed += 1
            continue
        product, _entry, event = found
        if doc["source"]["product"] != product:
            print("FAIL catalog:", name, "has source.product", doc["source"]["product"], "instead of", product)
            failed += 1
        if doc["subject"]["type"] not in event["subject_type"].split("|") or doc["subject"]["id_kind"] != event["id_kind"]:
            print("FAIL catalog:", name, "subject does not match the catalog")
            failed += 1
        extra = sorted(set(doc["data"]) - set(event["data_fields"]))
        if extra:
            print("FAIL catalog:", name, "data fields not in the catalog:", ", ".join(extra))
            failed += 1
    shipped = os.path.join(ROOT, "assets", "data", "catalog-fields.json")
    if os.path.exists(shipped):
        with open(shipped, encoding="utf-8") as handle:
            shipped_fields = json.load(handle)
        expected = {t: entry[2]["data_fields"] for t, entry in by_type.items()}
        if shipped_fields != expected:
            print("FAIL catalog: assets/data/catalog-fields.json differs from the catalog (regenerate it)")
            failed += 1
    if not failed:
        print("ok   catalog: %d types, all examples match" % len(by_type))
    return failed


def check_n8n_templates():
    """The n8n templates parse, carry no credentials and deduplicate on the event id. Returns the failure count."""
    failed = 0
    paths = sorted(glob.glob(os.path.join(ROOT, "docs", "n8n", "*.json")))
    for path in paths:
        name = os.path.basename(path)
        with open(path, encoding="utf-8") as handle:
            flow = json.load(handle)
        nodes = flow.get("nodes", [])
        if any("credentials" in node for node in nodes):
            print("FAIL n8n:", name, "contains credentials")
            failed += 1
        hooks = [n for n in nodes if n.get("type") == "n8n-nodes-base.webhook"]
        if not hooks or any(h["parameters"].get("authentication") != "headerAuth" for h in hooks):
            print("FAIL n8n:", name, "needs a webhook with header authentication")
            failed += 1
        dedupe = [n for n in nodes if n.get("type") == "n8n-nodes-base.removeDuplicates"]
        if not dedupe or "$json.body.id" not in dedupe[0]["parameters"].get("dedupeValue", ""):
            print("FAIL n8n:", name, "does not deduplicate on the event id")
            failed += 1
        names = {n["name"] for n in nodes}
        for source, outputs in flow.get("connections", {}).items():
            targets = [c["node"] for branch in outputs["main"] for c in branch]
            if source not in names or any(t not in names for t in targets):
                print("FAIL n8n:", name, "connection to an unknown node")
                failed += 1
    if paths and not failed:
        print("ok   n8n: %d template(s)" % len(paths))
    return failed


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

    failed += check_catalog(validator, docs)
    failed += check_n8n_templates()

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
