#!/usr/bin/env bash
# Exercise the MCP endpoint of the running demo by hand.
#   scripts/mcp.sh http://localhost:8766 apb_AGENT_SECRET
set -euo pipefail
BASE="${1:-http://localhost:8766}"; KEY="${2:?agent key missing}"; URL="$BASE/api/publisher/v1/mcp"
rpc() { curl -s -X POST "$URL" -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' -H 'Accept: application/json' -d "$1"; echo; }
echo "# initialize"; rpc '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl","version":"1"}}}'
echo "# tools/list (names only)"; rpc '{"jsonrpc":"2.0","id":2,"method":"tools/list"}' | python3 -c 'import json,sys; print(", ".join(t["name"] for t in json.load(sys.stdin)["result"]["tools"]))'
echo "# tools/call lint_draft"; rpc '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"lint_draft","arguments":{"section":"blog","locale":"en","title":"Hi","body":"Short."}}}' | python3 -c 'import json,sys; r=json.load(sys.stdin)["result"]; print("isError:", r["isError"], "| status:", r["structuredContent"]["status"]); [print("  ", c["level"].upper(), c["rule"], "-", c["message"]) for c in r["structuredContent"]["checks"]]'
