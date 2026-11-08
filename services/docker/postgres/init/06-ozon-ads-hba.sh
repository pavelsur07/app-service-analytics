# Граница ADR-052 для песочницы: роль svc_ozon_ads подключается
# только к своим БД. REVOKE CONNECT из 01-ozon-ads-controller.sql этого не
# обеспечивает: БД, созданные позже (тестовые базы PHP и их копии
# процессов paratest), получают CONNECT для PUBLIC заново. pg_hba
# действует на любые БД, в том числе будущие.
#
# Правила ставятся в начало файла (первое совпадение решает) один раз;
# повторный запуск ничего не меняет. Скрипт исполняет и initdb на пустом
# томе (sh-файл, подключается через source — без exit), и
# `make ozon-ads-controller-db` на живом.
hba="$PGDATA/pg_hba.conf"
if ! grep -q '^# ozon-ads-controller: ' "$hba"; then
	{
		echo '# ozon-ads-controller: роль сервиса — только к своим БД (ADR-052)'
		echo 'host  ozon_ads,ozon_ads_test  svc_ozon_ads  all  scram-sha-256'
		echo 'host  all                             svc_ozon_ads  all  reject'
		echo 'local all                             svc_ozon_ads       reject'
		cat "$hba"
	} > "$hba.new"
	cat "$hba.new" > "$hba"
	rm -f "$hba.new"
	# На временном сервере initdb reload пишет «postgresql.conf contains
	# errors» про listen_addresses — штатно, pg_hba при этом применяется.
	psql -q -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c 'SELECT pg_reload_conf()' > /dev/null
fi
