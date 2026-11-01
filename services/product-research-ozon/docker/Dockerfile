FROM python@sha256:516b51cb9db2ef061e465c803e50f64361b82cefd91e525b889b967b83e83527 AS dev
ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1 PYTHONPATH=/service/src
WORKDIR /service
COPY stage0/requirements.lock /tmp/requirements.lock
RUN python -m pip install --disable-pip-version-check --only-binary=:all: --require-hashes -r /tmp/requirements.lock
COPY . /service
USER 10001:10001
CMD ["python", "-m", "uvicorn", "product_research_ozon.bootstrap:application", "--factory", "--host", "0.0.0.0", "--port", "8080", "--no-access-log", "--no-proxy-headers", "--log-config", "/service/logging.json"]

FROM python@sha256:516b51cb9db2ef061e465c803e50f64361b82cefd91e525b889b967b83e83527 AS runtime
ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1 PYTHONPATH=/service/src
WORKDIR /service
COPY requirements-runtime.lock /tmp/requirements.lock
RUN python -m pip install --disable-pip-version-check --only-binary=:all: --require-hashes -r /tmp/requirements.lock
COPY src /service/src
COPY migrations /service/migrations
COPY alembic.ini /service/alembic.ini
COPY logging.json /service/logging.json
USER 10001:10001
CMD ["python", "-m", "uvicorn", "product_research_ozon.bootstrap:application", "--factory", "--host", "0.0.0.0", "--port", "8080", "--no-access-log", "--no-proxy-headers", "--log-config", "/service/logging.json"]
