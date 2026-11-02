# Shared dev/prod boundary also covers databases created later, not just existing ones.
hba="$PGDATA/pg_hba.conf"
if ! grep -q '^# product-research-ozon: ' "$hba"; then
    {
        echo '# product-research-ozon: own databases only (ADR-050)'
        echo 'host product_research_ozon,product_research_ozon_test svc_product_research_ozon all scram-sha-256'
        echo 'host all svc_product_research_ozon all reject'
        echo 'local all svc_product_research_ozon reject'
        cat "$hba"
    } > "$hba.new"
    cat "$hba.new" > "$hba"
    rm -f "$hba.new"
    psql -q -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c 'SELECT pg_reload_conf()' > /dev/null
fi
