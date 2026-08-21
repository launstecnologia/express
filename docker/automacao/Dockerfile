FROM python:3.11-slim-bookworm

# Instala dependências do sistema e Google Chrome
RUN apt-get update && apt-get install -y --no-install-recommends \
        wget gnupg2 curl ca-certificates unzip xvfb \
        libnss3 libatk1.0-0 libatk-bridge2.0-0 libcups2 \
        libdrm2 libgbm1 libxkbcommon0 libx11-xcb1 \
        libxcomposite1 libxdamage1 libxfixes3 libxrandr2 \
        libpango-1.0-0 libcairo2 libasound2 libdbus-1-3 \
        fonts-liberation libappindicator3-1 lsb-release xdg-utils \
    && wget -q https://dl.google.com/linux/direct/google-chrome-stable_current_amd64.deb \
    && apt-get install -y ./google-chrome-stable_current_amd64.deb \
    && rm -f google-chrome-stable_current_amd64.deb \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY automacao/requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY automacao/ .

RUN mkdir -p /tmp/automacao_screenshots /tmp/automacao_db /tmp/automacao_chrome

# Chrome usa xvfb (display virtual) para a extensão Capsolver funcionar
ENV AUTOMACAO_HEADLESS=true \
    AUTOMACAO_DB_PATH=/tmp/automacao_db/jobs.db \
    AUTOMACAO_SCREENSHOT_DIR=/tmp/automacao_screenshots \
    AUTOMACAO_CHROME_PROFILE_DIR=/tmp/automacao_chrome \
    DISPLAY=:99

EXPOSE 8001

CMD ["xvfb-run", "-a", "--server-args=-screen 0 1366x768x24", "uvicorn", "api:app", "--host", "0.0.0.0", "--port", "8001", "--workers", "1", "--reload"]
