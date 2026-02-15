FROM mcr.microsoft.com/devcontainers/php:1-8.2

RUN apt-get update \
  && apt-get install -y --no-install-recommends default-mysql-client \
  && docker-php-ext-install pdo_mysql \
  && rm -rf /var/lib/apt/lists/*
