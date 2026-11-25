import json
import subprocess

base = ["docker", "compose"]
fixture = json.loads(subprocess.check_output(
    base + ["exec", "-T", "web", "php", "-r",
            r"""require 'vendor/autoload.php'; $app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = $app->make('db');
echo json_encode([
    'payment_id' => $db->table('payment_operations')->where('state', 'declined')->where('amount', 48000)->value('id'),
    'run_id' => $db->table('reconciliation_items')->where('result', 'amount_mismatch')->orderByDesc('id')->value('run_id'),
]);"""],
    text=True,
))
if not fixture["payment_id"] or not fixture["run_id"]:
    raise SystemExit("Could not find the seeded incident and its reconciliation run.")

messages = [
    {"id": 1, "method": "initialize", "params": {
        "protocolVersion": "2025-11-25", "capabilities": {}, "clientInfo": {"name": "PayFlow smoke test", "version": "1"},
    }},
    {"method": "notifications/initialized"},
    {"id": 2, "method": "tools/list"},
    {"id": 3, "method": "tools/call", "params": {"name": "get_transaction", "arguments": {"payment_id": fixture["payment_id"]}}},
    {"id": 4, "method": "tools/call", "params": {"name": "list_refunds", "arguments": {"payment_id": fixture["payment_id"]}}},
    {"id": 5, "method": "tools/call", "params": {"name": "get_reconciliation_run", "arguments": {"run_id": fixture["run_id"]}}},
]
output = subprocess.run(
    base + ["run", "--rm", "-T", "mcp"], check=True, capture_output=True, text=True,
    input="".join(json.dumps({"jsonrpc": "2.0", **message}) + "\n" for message in messages),
).stdout
results = {reply["id"]: reply["result"] for reply in map(json.loads, output.splitlines()) if "result" in reply}
assert {tool["name"] for tool in results[2]["tools"]} == {"get_transaction", "list_refunds", "get_reconciliation_run"}
assert all(tool["annotations"]["readOnlyHint"] for tool in results[2]["tools"])
assert results[3]["structuredContent"]["payment"]["state"] == "declined"
assert results[4]["structuredContent"]["refunds"] == []
assert any(item["result"] == "amount_mismatch" for item in results[5]["structuredContent"]["exceptions"])
print("All three stdio tools returned the expected seeded evidence.")
