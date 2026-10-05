#!/bin/sh
set -eu

port="${PORT:-10000}"
sed -i -E "s/^Listen [0-9]+$/Listen ${port}/" /etc/apache2/ports.conf
sed -i -E "s|<VirtualHost \\*:80>|<VirtualHost *:${port}>|" \
    /etc/apache2/sites-available/000-default.conf

exec apache2-foreground
