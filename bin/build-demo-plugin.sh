#!/usr/bin/env sh
set -eu

cd "$(dirname "$0")/../docs/plugins/demo-payment-gateway"
zip -r /tmp/demo-payment-gateway.zip plugin.json README.md src
echo "/tmp/demo-payment-gateway.zip"
