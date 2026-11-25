EXPLAIN ANALYZE
SELECT id, rail, state, amount, created_at FROM payment_operations IGNORE INDEX (payment_operations_merchant_kind_created_at_id_index)
WHERE merchant='demo' AND kind='payment' ORDER BY created_at DESC, id DESC LIMIT 10;

EXPLAIN ANALYZE
SELECT id, rail, state, amount, created_at FROM payment_operations
WHERE merchant='demo' AND kind='payment' ORDER BY created_at DESC, id DESC LIMIT 10;
