FROM python:3.10-slim

RUN apt-get update && apt-get install -y --no-install-recommends \
    build-essential libopenblas-dev git curl libx11-dev libxrandr-dev libxinput-dev \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY server/requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

RUN git clone https://github.com/alibaba/neug.git /tmp/neug && \
    cd /tmp/neug/tools/python_bind && \
    pip install -r requirements.txt && \
    python3 setup.py build_ext && \
    python3 setup.py bdist_wheel && \
    pip install dist/*.whl && \
    rm -rf /tmp/neug

COPY server/ ./server/
COPY web/ ./web/
COPY .env.example .env

RUN mkdir -p /app/server/zvec_db /app/data

EXPOSE 5000
ENV REDIS_HOST=redis
ENV OLLAMA_URL=http://ollama:11434/api/generate
CMD ["python", "server/server.py"]
