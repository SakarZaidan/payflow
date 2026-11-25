set -e
[[ "$PAYFLOW_MCP_DB_PASSWORD" =~ ^[a-f0-9]{48}$ ]] || { echo "Invalid MCP database password format"; exit 1; }
docker_process_sql <<SQL
CREATE USER 'payflow_reader'@'%' IDENTIFIED BY '${PAYFLOW_MCP_DB_PASSWORD}';
GRANT SELECT ON \`${MYSQL_DATABASE}\`.* TO 'payflow_reader'@'%';
SQL
