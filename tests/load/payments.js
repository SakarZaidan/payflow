import http from 'k6/http';
import { check, sleep, fail } from 'k6';
import exec from 'k6/execution';

const base = __ENV.BASE_URL || 'http://127.0.0.1:8080';
const burst = __ENV.MODE === 'burst';
const scenario = burst
    ? { executor: 'per-vu-iterations', vus: 50, iterations: 1, maxDuration: '20s' }
    : { executor: 'constant-arrival-rate', rate: 50, timeUnit: '1s', duration: __ENV.DURATION || '5m', preAllocatedVUs: 60, maxVUs: 160 };

export const options = {
    scenarios: { payments: scenario },
    thresholds: {
        'http_req_duration{operation:accept}': ['p(95)<250'],
        http_req_failed: ['rate<0.005'],
        checks: ['rate==1'],
        ...(!burst ? { dropped_iterations: ['count==0'] } : {}),
    },
};

function submit(key, rail) {
    const response = http.post(base + '/api/v1/payments', JSON.stringify({ rail, amount: 1250 }), {
        headers: { Authorization: 'Bearer ' + __ENV.PAYFLOW_MERCHANT_TOKEN, 'Content-Type': 'application/json', 'Idempotency-Key': key },
        tags: { operation: 'accept' },
    });
    const accepted = check(response, { accepted: (result) => result.status === 202 && typeof result.json('id') === 'string' });
    return accepted ? response.json('id') : null;
}

export function setup() {
    if (!__ENV.PAYFLOW_MERCHANT_TOKEN || !__ENV.RUN_ID) fail('Set PAYFLOW_MERCHANT_TOKEN and RUN_ID.');
    return { run: __ENV.RUN_ID };
}

export default function (data) {
    const iteration = exec.scenario.iterationInTest;
    const shared = burst || iteration % 5 === 0;
    submit(shared ? data.run + ':shared' : data.run + ':' + iteration, shared || iteration % 2 ? 'knet' : 'card');
}

export function teardown(data) {
    const id = submit(data.run + ':shared', 'knet');
    if (!id) fail('Shared request was rejected');
    for (let attempt = 0; attempt < 30; attempt++) {
        const response = http.get(base + '/api/v1/payments/' + id, {
            headers: { Authorization: 'Bearer ' + __ENV.PAYFLOW_MERCHANT_TOKEN },
        });
        if (response.status === 200 && response.json('state') === 'captured') {
            console.log('Shared operation captured: ' + id);
            return;
        }
        sleep(1);
    }
    fail('Shared payment did not drain within 30 seconds.');
}
