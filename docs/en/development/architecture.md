# Architecture

## Telegram Layer

Receives updates through webhook or long polling, manages menus, order flow, payments, and service display.

## Orders And Payments

Orders represent customer intent. Payments represent transactions. Payment approval finalizes provisioning through `PaymentApprovalService`.

## Payment Gateways

Core gateway drivers handle built-in types. Plugin gateway drivers are loaded through the plugin bridge after doctor validation.

## Plugin System

Plugin packages are installed under `var/plugins/{code}`. Runtime support currently focuses on `payment_gateway` plugins.

## VPN Provisioning

VPN panels and inbounds define remote provisioning targets. Services store provisioned client data, subscription URLs, and config links.

## Automation

Automation commands sync usage, check expiry, send notifications, suspend services, and expire incomplete orders.

## Admin

EasyAdmin provides operational CRUD, actions, and diagnostics with fa/en and RTL/LTR support.
