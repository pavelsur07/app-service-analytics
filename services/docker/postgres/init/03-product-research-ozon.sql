-- Sandbox only. Production provisioning is deliberately not enabled in stage 1.
SELECT 'CREATE ROLE svc_product_research_ozon LOGIN PASSWORD ''product_research_dev'''
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'svc_product_research_ozon')\gexec
SELECT 'CREATE DATABASE product_research_ozon OWNER svc_product_research_ozon'
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'product_research_ozon')\gexec
SELECT 'CREATE DATABASE product_research_ozon_test OWNER svc_product_research_ozon'
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'product_research_ozon_test')\gexec
REVOKE CONNECT ON DATABASE product_research_ozon FROM PUBLIC;
REVOKE CONNECT ON DATABASE product_research_ozon_test FROM PUBLIC;
