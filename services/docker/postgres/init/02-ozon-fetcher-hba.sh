# Граница ADR-043 п. 3 для песочницы: роль svc_ozon_fetcher подключается
# только к своим БД. REVOKE CONNECT из 01-ozon-fetcher.sql этого не
# обеспечивает: БД, созданные позже (тестовые базы PHP и их копии
# процессов paratest), получают CONNECT для PUBLIC заново. pg_hba
# действует на любые БД, в том числе будущие.
#
# Правила ставятся в начало файла (первое совпадение решает) один раз;
# повторный запуск ничего не меняет. Скрипт исполняет и initdb на пустом
# томе (sh-файл, подключается через source — без exit), и
# `make ozon-fetcher-db` на живом.
hba="$PGDATA/pg_hba.conf"
if ! grep -q '^# ozon-fetcher: ' "$hba"; then
	{
		echo '# ozon-fetcher: роль сервиса — только к своим БД (ADR-043 п. 3)'
		echo 'host  ozon_fetcher,ozon_fetcher_test  svc_ozon_fetcher  all  scram-sha-256'
		echo 'host  all                             svc_ozon_fetcher  all  reject'
		echo 'local all                             svc_ozon_fetcher       reject'
		cat "$hba"
	} > "$hba.new"
	cat "$hba.new" > "$hba"
	rm -f "$hba.new"
	# На временном сервере initdb reload пишет «postgresql.conf contains
	# errors» про listen_addresses — штатно, pg_hba при этом применяется.
	psql -q -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c 'SELECT pg_reload_conf()' > /dev/null
fi
