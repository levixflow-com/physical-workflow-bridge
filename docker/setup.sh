#!/bin/sh
# First run: creates .env with random credentials, then installs the PHP dependencies.
# It never overwrites an existing .env.
set -e
cd /repo

if [ ! -f .env ]; then
    key=$(head -c 32 /dev/urandom | base64)
    # 24 characters: the board truncates broker passwords longer than 31.
    password=$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')
    sed -e "s|^APP_KEY=.*|APP_KEY=base64:$key|" -e "s|^MQTT_PASSWORD=.*|MQTT_PASSWORD=$password|" .env.example > .env
    echo ".env created with random credentials."
else
    echo ".env already there: left as it is."
fi

cd web
# Laravel opens this file even when it is empty: the real values come from compose.
touch .env
composer install --no-interaction --no-progress
