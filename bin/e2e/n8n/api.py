#!/usr/bin/env python3
"""Small client of a local n8n (public API /api/v1 with an API key) for bin/e2e/n8n.sh. Not shipped.

    api.py setup                          owner account + API key (stored in $N8N_STATE)
    api.py cred <name> <header> <value>   Header-Auth credential, prints its id
    api.py import <file> [cred_id]        imports a workflow file (name, nodes, connections, settings);
                                          with cred_id the Header-Auth webhook nodes get that credential; prints the id
    api.py use-cred <wf_id> <cred_id>     switches the Header-Auth credential of a workflow (rotation)
    api.py activate|deactivate <wf_id>    prints the JSON answer (status code first)
    api.py get <wf_id>                    workflow as JSON
    api.py executions <wf_id> [limit]     executions with data, newest first (JSON)
"""
import json
import os
import sys
import urllib.error
import urllib.request

BASE = os.environ.get("N8N_URL", "http://127.0.0.1:5678")
STATE = os.environ.get("N8N_STATE", "/home/user/wp-e2e/logs/n8n-api.json")
OWNER = {"email": "owner@example.test", "firstName": "E2E", "lastName": "Owner", "password": "E2e-n8n-Pass-2026"}
# Local only: never through the agent proxy.
OPENER = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor())


def call(method, path, body=None, key=None):
    data = None if body is None else json.dumps(body).encode()
    req = urllib.request.Request(BASE + path, data=data, method=method)
    req.add_header("Content-Type", "application/json")
    if key:
        req.add_header("X-N8N-API-KEY", key)
    try:
        with OPENER.open(req, timeout=60) as res:
            raw = res.read().decode() or "null"
            return res.status, json.loads(raw)
    except urllib.error.HTTPError as err:
        raw = err.read().decode()
        try:
            return err.code, json.loads(raw)
        except ValueError:
            return err.code, raw


def api_key():
    with open(STATE) as fh:
        return json.load(fh)["apiKey"]


def setup():
    call("POST", "/rest/owner/setup", OWNER)  # 400 when the owner exists already
    code, _ = call("POST", "/rest/login", {"emailOrLdapLoginId": OWNER["email"], "password": OWNER["password"]})
    if code != 200:
        sys.exit("login failed: %s" % code)
    scopes = ["credential:create", "credential:delete", "workflow:create", "workflow:read", "workflow:list",
              "workflow:update", "workflow:delete", "workflow:activate", "workflow:deactivate",
              "execution:read", "execution:list"]
    code, res = call("POST", "/rest/api-keys", {"label": "e2e-%d" % os.getpid(), "expiresAt": None, "scopes": scopes})
    if code != 200:
        sys.exit("api key failed: %s %s" % (code, res))
    with open(STATE, "w") as fh:
        json.dump({"apiKey": res["data"]["rawApiKey"]}, fh)
    print("ok")


def with_cred(nodes, cred_id):
    for node in nodes:
        if node.get("type") == "n8n-nodes-base.webhook" and node.get("parameters", {}).get("authentication") == "headerAuth":
            node["credentials"] = {"httpHeaderAuth": {"id": cred_id, "name": "Workflow Captain " + cred_id}}
    return nodes


def main(argv):
    cmd = argv[1] if len(argv) > 1 else ""
    if cmd == "setup":
        return setup()
    key = api_key()
    if cmd == "cred":
        code, res = call("POST", "/api/v1/credentials", {"name": argv[2], "type": "httpHeaderAuth", "data": {"name": argv[3], "value": argv[4]}}, key)
        print(res["id"] if code == 200 else "ERROR %s %s" % (code, res))
    elif cmd == "import":
        with open(argv[2]) as fh:
            wf = json.load(fh)
        body = {k: wf[k] for k in ("name", "nodes", "connections", "settings") if k in wf}
        if len(argv) > 3:
            with_cred(body["nodes"], argv[3])
        code, res = call("POST", "/api/v1/workflows", body, key)
        print(res["id"] if code == 200 else "ERROR %s %s" % (code, json.dumps(res)[:400]))
    elif cmd == "use-cred":
        code, wf = call("GET", "/api/v1/workflows/" + argv[2], None, key)
        body = {k: wf[k] for k in ("name", "nodes", "connections", "settings")}
        body["settings"] = {"executionOrder": wf["settings"].get("executionOrder", "v1")}
        with_cred(body["nodes"], argv[3])
        code, res = call("PUT", "/api/v1/workflows/" + argv[2], body, key)
        print("%s %s" % (code, res.get("id") if isinstance(res, dict) else res))
    elif cmd in ("activate", "deactivate"):
        code, res = call("POST", "/api/v1/workflows/%s/%s" % (argv[2], cmd), {}, key)
        print("%s %s" % (code, json.dumps({"active": res.get("active")} if isinstance(res, dict) and "active" in res else res)[:400]))
    elif cmd == "get":
        code, res = call("GET", "/api/v1/workflows/" + argv[2], None, key)
        print(json.dumps(res))
    elif cmd == "executions":
        limit = argv[3] if len(argv) > 3 else "20"
        code, res = call("GET", "/api/v1/executions?includeData=true&limit=%s&workflowId=%s" % (limit, argv[2]), None, key)
        print(json.dumps(res.get("data", []) if isinstance(res, dict) else res))
    else:
        sys.exit(__doc__)


if __name__ == "__main__":
    main(sys.argv)
