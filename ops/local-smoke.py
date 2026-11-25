import concurrent.futures
import json
import os
from pathlib import Path
import subprocess
import time
from urllib.error import HTTPError
from urllib.request import Request, urlopen
from uuid import uuid4

base = os.environ.get("PAYFLOW_QA_URL", "http://127.0.0.1:8080")
token = next(
    line.split("=", 1)[1] for line in Path(".env").read_text().splitlines()
    if line.startswith("PAYFLOW_MERCHANT_TOKEN=")
)
tag = "local-smoke-" + uuid4().hex
compose = ["docker", "compose"]


def request(path, key=None, body=None):
    headers = {"Authorization": "Bearer " + token, "Accept": "application/json"}
    if key:
        headers |= {"Idempotency-Key": key, "Content-Type": "application/json"}
    data = json.dumps(body).encode() if body else None
    try:
        with urlopen(Request(base + path, data=data, headers=headers), timeout=10) as response:
            return response.status, json.load(response)
    except HTTPError as error:
        return error.code, json.load(error)


def await_state(path, state):
    for _ in range(35):
        status, record = request(path)
        if status == 200 and record["state"] == state:
            return record
        time.sleep(1)
    raise AssertionError(f"{path} did not reach {state}")


subprocess.run(compose + ["stop", "worker"], check=True)
try:
    body = {"rail": "knet", "amount": 1250}
    status, operation = request("/api/v1/payments", tag, body)
    assert status == 202
    assert request(operation["status_url"])[1]["state"] == "queued"
    assert request("/api/v1/payments", tag, body) == (202, operation)
finally:
    subprocess.run(compose + ["start", "worker"], check=True)

await_state(operation["status_url"], "captured")
refund_path = operation["status_url"] + "/refunds"
with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
    refunds = list(pool.map(lambda n: request(refund_path, f"{tag}-{n}", {"amount": 800}), range(2)))
assert sorted(code for code, _ in refunds) == [202, 409]
refund = next(value for code, value in refunds if code == 202)
await_state(refund["status_url"], "refunded")
for _ in range(2):
    subprocess.run(compose + ["exec", "-T", "web", "php", "artisan", "payflow:deliver", "sim_" + operation["id"]], check=True)
assert request(operation["status_url"])[1]["state"] == "captured"
print("Worker restart, simultaneous refund reservations, and signed callback replay passed.")
