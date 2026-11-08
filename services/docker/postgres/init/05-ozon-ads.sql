-- Sandbox only. Production provisioning is a separate operational step.
SELECT 'CREATE ROLE svc_ozon_ads LOGIN PASSWORD ''ozon_ads_dev'''
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'svc_ozon_ads')\gexec
SELECT 'CREATE DATABASE ozon_ads OWNER svc_ozon_ads'
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'ozon_ads')\gexec
SELECT 'CREATE DATABASE ozon_ads_test OWNER svc_ozon_ads'
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'ozon_ads_test')\gexec
